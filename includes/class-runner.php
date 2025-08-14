<?php
/**
 * Command runner abstraction.
 *
 * @package PorkPress\SSL
 */

namespace PorkPress\SSL;

/**
 * Execute external commands with fallbacks and optional sudo.
 */
class Runner {
    /** Cached detection of available method. */
    protected static $method = null;

    /** Cached sudo checks. */
    protected static $sudo_ok = array();

    /**
     * Determine if a function is available and not disabled.
     */
    protected static function function_available( string $func ): bool {
        if ( ! function_exists( $func ) ) {
            return false;
        }
        $disabled = explode( ',', (string) ini_get( 'disable_functions' ) );
        $disabled = array_map( 'trim', $disabled );
        return ! in_array( $func, $disabled, true );
    }

    /**
     * Detect execution method.
     */
    public static function method(): string {
        if ( self::$method ) {
            return self::$method;
        }
        if ( self::function_available( 'proc_open' ) ) {
            // Prefer proc_open to avoid direct shell access per WordPress guidelines.
            self::$method = 'proc';
        } else {
            self::$method = 'wpcli';
        }
        return self::$method;
    }

    /**
     * Execute a command without any sudo handling.
     *
     * @param string $cmd Command to run.
     * @return array{code:int,output:string}
     */
    protected static function raw_run( string $cmd ): array {
        $method = self::method();
        if ( 'proc' === $method ) {
            $desc = array(
                1 => array( 'pipe', 'w' ),
                2 => array( 'pipe', 'w' ),
            );
            $proc = proc_open( $cmd, $desc, $pipes );
            if ( ! is_resource( $proc ) ) {
                return array( 'code' => 1, 'output' => '' );
            }
            $output = stream_get_contents( $pipes[1] ) . stream_get_contents( $pipes[2] );
            foreach ( $pipes as $p ) {
                fclose( $p );
            }
            $code = proc_close( $proc );
            return array( 'code' => (int) $code, 'output' => $output );
        }
        return array( 'code' => 127, 'output' => 'command execution not available' );
    }

    /**
     * Check if sudo can run a given binary non-interactively.
     */
    protected static function sudo_available( string $bin ): bool {
        $escaped = escapeshellarg( $bin );
        if ( isset( self::$sudo_ok[ $escaped ] ) ) {
            return self::$sudo_ok[ $escaped ];
        }
        $test   = 'sudo -n ' . $escaped . ' --version';
        $result = self::raw_run( $test );
        self::$sudo_ok[ $escaped ] = ( 0 === $result['code'] );
        return self::$sudo_ok[ $escaped ];
    }

    /**
     * Maybe prefix a command with sudo depending on context.
     */
    protected static function maybe_sudo( string $cmd, string $context ): string {
        $use = false;
        if ( 'certbot' === $context ) {
            $use = function_exists( '\\get_site_option' ) ? (bool) \get_site_option( 'porkpress_ssl_sudo_certbot', 0 ) : false;
        } elseif ( 'apache' === $context ) {
            $use = function_exists( '\\get_site_option' ) ? (bool) \get_site_option( 'porkpress_ssl_sudo_apache', 0 ) : false;
        }
        if ( ! $use ) {
            return $cmd;
        }
        $bin = strtok( $cmd, ' ' );
        if ( ! self::sudo_available( $bin ) ) {
            return $cmd;
        }
        return 'sudo ' . $cmd;
    }

    /**
     * Run a command.
     *
     * @param string $cmd     Command to execute.
     * @param string $context Command context for sudo allow-list.
     * @return array{code:int,output:string}
     */
    public static function run( string $cmd, string $context = '' ): array {
        $cmd = self::maybe_sudo( $cmd, $context );
        return self::raw_run( $cmd );
    }

    /**
     * Check whether a command exists in PATH or at a given path.
     */
    public static function command_exists( string $cmd ): bool {
        if ( '' === $cmd ) {
            return false;
        }
        if ( '/' === $cmd[0] ) {
            return is_executable( $cmd );
        }
        $result = self::raw_run( 'command -v ' . escapeshellarg( $cmd ) . ' 2>/dev/null' );
        return '' !== trim( $result['output'] );
    }

    /**
     * Describe current runner mode for health output.
     */
    public static function describe(): string {
        $mode = self::method();
        switch ( $mode ) {
            case 'proc':
                $mode = 'proc_open';
                break;
            default:
                $mode = 'wp-cli wrapper required';
        }
        $sudo = array();
        if ( function_exists( '\\get_site_option' ) ) {
            if ( \get_site_option( 'porkpress_ssl_sudo_certbot', 0 ) ) {
                $sudo[] = 'certbot';
            }
            if ( \get_site_option( 'porkpress_ssl_sudo_apache', 0 ) ) {
                $sudo[] = 'apache';
            }
        }
        if ( $sudo ) {
            $mode .= ' (sudo: ' . implode( ',', $sudo ) . ')';
        }
        return $mode;
    }
}

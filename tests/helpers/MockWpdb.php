<?php
class MockWpdb {
    public $data = [];
    public $base_prefix = 'wp_';
    public $last_error = '';
    private $in_transaction = false;
    private $backup = [];

    public function get_charset_collate() {
        return '';
    }

    public function prepare( $query, $args ) {
        if ( ! is_array( $args ) ) {
            $args = func_get_args();
            array_shift( $args );
        }
        $args = array_map( function ( $a ) {
            return is_int( $a ) ? $a : "'{$a}'";
        }, $args );

        return vsprintf( $query, $args );
    }

    private function table_from_sql( $sql ) {
        if ( preg_match( '/FROM\s+(\w+)/', $sql, $m ) ) {
            return $m[1];
        }
        return '';
    }

    public function insert( $table, $data, $format = null ) {
        foreach ( $this->data[ $table ] ?? [] as $row ) {
            if ( $row['domain'] === $data['domain'] ) {
                $this->last_error = 'Duplicate entry';
                return false;
            }
        }
        $this->data[ $table ][] = $data;
        return 1;
    }

    public function get_results( $sql, $output ) {
        $table = $this->table_from_sql( $sql );
        $rows  = $this->data[ $table ] ?? [];
        if ( preg_match( '/site_id\s*=\s*(\d+)/', $sql, $m ) ) {
            $site_id = (int) $m[1];
            $rows    = array_filter( $rows, fn( $r ) => $r['site_id'] == $site_id );
        }
        if ( preg_match( "/domain\s*=\s*'([^']+)'/", $sql, $m ) ) {
            $domain = $m[1];
            $rows   = array_filter( $rows, fn( $r ) => $r['domain'] == $domain );
        }
        return array_values( $rows );
    }

    public function update( $table, $data, $where, $formats, $where_formats ) {
        foreach ( $this->data[ $table ] as &$row ) {
            if ( $row['site_id'] == $where['site_id'] && $row['domain'] == $where['domain'] ) {
                foreach ( $data as $k => $v ) {
                    $row[ $k ] = $v;
                }
                return 1;
            }
        }
        return false;
    }

    public function delete( $table, $where, $where_formats ) {
        foreach ( $this->data[ $table ] as $i => $row ) {
            if ( $row['site_id'] == $where['site_id'] && $row['domain'] == $where['domain'] ) {
                unset( $this->data[ $table ][ $i ] );
                $this->data[ $table ] = array_values( $this->data[ $table ] );
                return 1;
            }
        }
        return false;
    }

    public function query( $sql ) {
        $upper = strtoupper( trim( $sql ) );
        if ( 'START TRANSACTION' === $upper || 'BEGIN' === $upper ) {
            $this->in_transaction = true;
            $this->backup         = $this->data;
            return true;
        }
        if ( 'ROLLBACK' === $upper ) {
            if ( $this->in_transaction ) {
                $this->data          = $this->backup;
                $this->in_transaction = false;
                $this->backup        = [];
            }
            return true;
        }
        if ( 'COMMIT' === $upper ) {
            $this->in_transaction = false;
            $this->backup        = [];
            return true;
        }

        if ( preg_match( "/UPDATE\s+(\w+)\s+SET\s+is_primary\s+=\s+CASE\s+WHEN\s+domain\s+=\s*'([^']+)'\s+THEN\s+1\s+ELSE\s+0\s+END\s+WHERE\s+site_id\s+=\s*(\d+)/i", $sql, $m ) ) {
            $table  = $m[1];
            $domain = $m[2];
            $site   = (int) $m[3];
            foreach ( $this->data[ $table ] as &$row ) {
                if ( $row['site_id'] == $site ) {
                    $row['is_primary'] = $row['domain'] === $domain ? 1 : 0;
                }
            }
            return true;
        }
        return false;
    }
}

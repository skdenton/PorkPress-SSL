(function () {
    function copyText(text) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            return navigator.clipboard.writeText(text);
        }

        return new Promise(function (resolve, reject) {
            var textarea = document.createElement('textarea');
            textarea.value = text;
            textarea.setAttribute('readonly', '');
            textarea.style.position = 'absolute';
            textarea.style.left = '-9999px';
            document.body.appendChild(textarea);
            textarea.select();

            try {
                document.execCommand('copy');
                resolve();
            } catch (err) {
                reject(err);
            } finally {
                document.body.removeChild(textarea);
            }
        });
    }

    function showMessage(target, message, isError) {
        var status = target.nextElementSibling;

        if (!status || !status.classList.contains('porkpress-copy-status')) {
            status = document.createElement('span');
            status.className = 'porkpress-copy-status';
            status.setAttribute('aria-live', 'polite');
            target.insertAdjacentElement('afterend', status);
        }

        status.textContent = message;

        if (isError) {
            status.classList.add('is-error');
        } else {
            status.classList.remove('is-error');
        }

        setTimeout(function () {
            status.textContent = '';
            status.classList.remove('is-error');
        }, 2500);
    }

    function onClick(event) {
        event.preventDefault();
        var button = event.currentTarget;
        var command = button.getAttribute('data-command');

        if (!command) {
            return;
        }

        copyText(command)
            .then(function () {
                showMessage(button, (window.porkpressSslActions && window.porkpressSslActions.copied) || 'Copied', false);
            })
            .catch(function () {
                showMessage(button, (window.porkpressSslActions && window.porkpressSslActions.failed) || 'Unable to copy', true);
            });
    }

    document.addEventListener('DOMContentLoaded', function () {
        var buttons = document.querySelectorAll('.porkpress-copy-command');
        buttons.forEach(function (button) {
            button.addEventListener('click', onClick);
        });
    });
})();

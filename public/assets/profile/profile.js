(function () {
    const transliterationMap = {
        а: 'a',
        б: 'b',
        в: 'v',
        г: 'g',
        д: 'd',
        е: 'e',
        ё: 'e',
        ж: 'zh',
        з: 'z',
        и: 'i',
        й: 'y',
        к: 'k',
        л: 'l',
        м: 'm',
        н: 'n',
        о: 'o',
        п: 'p',
        р: 'r',
        с: 's',
        т: 't',
        у: 'u',
        ф: 'f',
        х: 'h',
        ц: 'c',
        ч: 'ch',
        ш: 'sh',
        щ: 'sch',
        ъ: '',
        ы: 'y',
        ь: '',
        э: 'e',
        ю: 'yu',
        я: 'ya',
    };

    function sourceFromLabel(value) {
        return value
            .trim()
            .toLowerCase()
            .split('')
            .map((symbol) => transliterationMap[symbol] ?? symbol)
            .join('')
            .replace(/[^a-z0-9_-]+/g, '_')
            .replace(/_+/g, '_')
            .replace(/^[_-]+|[_-]+$/g, '');
    }

    function snapshotUrlFromParts(host, channel) {
        host = host.trim().replace(/\/+$/g, '');

        if (host === '') {
            return '';
        }

        if (!/^https?:\/\//i.test(host)) {
            host = 'http://' + host;
        }

        const channelNumber = /^\d+$/.test(channel) && Number(channel) > 0 ? channel : '1';

        return host + '/cgi-bin/snapshot.cgi?channel=' + channelNumber;
    }

    document.querySelectorAll('.js-camera-form').forEach((form) => {
        const labelInput = form.querySelector('[data-role="label"]');
        const sourceInput = form.querySelector('[data-role="source"]');
        const hostInput = form.querySelector('[data-role="host"]');
        const channelInput = form.querySelector('[data-role="channel"]');
        const snapshotInput = form.querySelector('[data-role="snapshot-url"]');

        if (sourceInput) {
            if (sourceInput.value.trim() !== '') {
                sourceInput.dataset.touched = '1';
            }

            sourceInput.addEventListener('input', () => {
                sourceInput.dataset.touched = '1';
            });
        }

        if (snapshotInput) {
            if (snapshotInput.value.trim() !== '') {
                snapshotInput.dataset.touched = '1';
            }

            snapshotInput.addEventListener('input', () => {
                snapshotInput.dataset.touched = '1';
            });
        }

        function updateSource() {
            if (!labelInput || !sourceInput) {
                return;
            }

            if (sourceInput.dataset.touched === '1' && sourceInput.value.trim() !== '') {
                return;
            }

            sourceInput.value = sourceFromLabel(labelInput.value);
        }

        function updateSnapshotUrl() {
            if (!hostInput || !channelInput || !snapshotInput) {
                return;
            }

            if (snapshotInput.dataset.touched === '1' && snapshotInput.value.trim() !== '') {
                return;
            }

            snapshotInput.value = snapshotUrlFromParts(hostInput.value, channelInput.value);
        }

        labelInput?.addEventListener('input', updateSource);
        hostInput?.addEventListener('input', updateSnapshotUrl);
        channelInput?.addEventListener('input', updateSnapshotUrl);

        updateSource();
        updateSnapshotUrl();
    });

    document.querySelectorAll('.js-copy-button').forEach((button) => {
        button.addEventListener('click', async () => {
            const scope = button.closest('.input-group') ?? button.closest('tr') ?? button.closest('.modal-content') ?? document;
            const field = scope.querySelector('.js-copy-value');
            const value = field?.value ?? '';

            if (value === '') {
                return;
            }

            try {
                if (navigator.clipboard) {
                    await navigator.clipboard.writeText(value);
                } else if (field) {
                    field.select();
                    document.execCommand('copy');
                    field.blur();
                }
            } catch (error) {
                if (field) {
                    field.select();
                    document.execCommand('copy');
                    field.blur();
                }
            }

            const oldText = button.textContent;
            button.textContent = 'Скопировано';

            window.setTimeout(() => {
                button.textContent = oldText;
            }, 1500);
        });
    });

    document.querySelectorAll('[data-role="generate-secret"]').forEach((button) => {
        button.addEventListener('click', () => {
            const input = document.querySelector('[data-role="webhook-secret"]');

            if (!input) {
                return;
            }

            const alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
            const bytes = new Uint8Array(12);
            crypto.getRandomValues(bytes);

            input.value = Array.from(bytes, (byte) => alphabet[byte % alphabet.length]).join('');
            input.type = 'text';
            input.focus();
        });
    });
})();

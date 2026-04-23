document.addEventListener('DOMContentLoaded', function () {
    const mapsUrlInput = document.getElementById('mrg_maps_url');
    const serviceUrlInput = document.getElementById('mrg_scraper_service_url');

    function markFieldState(field, state) {
        if (!field) {
            return;
        }

        if (state === 'ok') {
            field.style.borderColor = '#46b450';
            return;
        }

        if (state === 'error') {
            field.style.borderColor = '#dc3232';
            return;
        }

        field.style.borderColor = '';
    }

    if (mapsUrlInput) {
        mapsUrlInput.addEventListener('input', function () {
            const isValid = this.value.trim().indexOf('google.com/maps/') !== -1;
            markFieldState(this, this.value.trim() ? (isValid ? 'ok' : 'error') : '');
        });
    }

    if (serviceUrlInput) {
        serviceUrlInput.addEventListener('input', function () {
            const isValid = /^https?:\/\//i.test(this.value.trim());
            markFieldState(this, this.value.trim() ? (isValid ? 'ok' : 'error') : '');
        });
    }

    const btnUploadAvatar = document.getElementById('mrg_btn_upload_avatar');
    const btnRemoveAvatar = document.getElementById('mrg_btn_remove_avatar');
    const inputAuthorPhoto = document.getElementById('mrg_author_photo');
    const imgPreview = document.getElementById('mrg_avatar_preview');
    const placeholder = document.getElementById('mrg_avatar_placeholder');

    if (btnUploadAvatar) {
        btnUploadAvatar.addEventListener('click', function (e) {
            e.preventDefault();

            const mediaUploader = wp.media({
                title: 'Seleccionar avatar',
                button: { text: 'Usar este avatar' },
                multiple: false
            });

            mediaUploader.on('select', function () {
                const attachment = mediaUploader.state().get('selection').first().toJSON();
                inputAuthorPhoto.value = attachment.url;
                imgPreview.src = attachment.url;
                imgPreview.style.display = 'block';
                placeholder.style.display = 'none';
                btnRemoveAvatar.style.display = 'inline-block';
            });

            mediaUploader.open();
        });
    }

    if (btnRemoveAvatar) {
        btnRemoveAvatar.addEventListener('click', function (e) {
            e.preventDefault();
            inputAuthorPhoto.value = '';
            imgPreview.src = '';
            imgPreview.style.display = 'none';
            placeholder.style.display = 'block';
            btnRemoveAvatar.style.display = 'none';
        });
    }

    const btnUpdateManual = document.getElementById('mrg_btn_update_manual');
    if (btnUpdateManual) {
        btnUpdateManual.addEventListener('click', function () {
            const statusSpan = document.getElementById('mrg_manual_update_status');

            btnUpdateManual.disabled = true;
            btnUpdateManual.innerText = 'Importando...';
            statusSpan.innerText = '';

            fetch(mrg_admin_vars.ajax_url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    action: 'mrg_update_reviews_manual',
                    nonce: mrg_admin_vars.nonce
                })
            })
                .then(function (res) {
                    return res.json();
                })
                .then(function (data) {
                    if (data.success) {
                        statusSpan.innerText = 'OK: ' + data.data.message + ' (' + data.data.timestamp + ')';
                        statusSpan.style.color = '#46b450';

                        setTimeout(function () {
                            location.reload();
                        }, 1800);
                    } else {
                        statusSpan.innerText = 'Error: ' + data.data;
                        statusSpan.style.color = '#dc3232';
                    }
                })
                .catch(function () {
                    statusSpan.innerText = 'Error al conectar con WordPress.';
                    statusSpan.style.color = '#dc3232';
                })
                .finally(function () {
                    btnUpdateManual.disabled = false;
                    btnUpdateManual.innerText = 'Importar reseñas ahora';
                });
        });
    }
});

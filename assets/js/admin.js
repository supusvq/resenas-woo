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

    const btnRegisterSite = document.getElementById('mrg_btn_register_site');
    const btnConnectGoogle = document.getElementById('mrg_btn_connect_google');
    const siteTokenInput = document.getElementById('mrg_service_site_token');
    const siteTokenStatus = document.getElementById('mrg_site_token_status');
    const btnLoadLocations = document.getElementById('mrg_btn_load_locations');
    const btnSaveLocation = document.getElementById('mrg_btn_save_location');
    const locationsSelect = document.getElementById('mrg_google_locations_select');
    const locationStatus = document.getElementById('mrg_google_location_status');

    function setSiteStatus(message, ok) {
        if (!siteTokenStatus) {
            return;
        }

        siteTokenStatus.innerText = message;
        siteTokenStatus.style.color = ok ? '#46b450' : '#dc3232';
    }

    function setLocationStatus(message, ok) {
        if (!locationStatus) {
            return;
        }

        locationStatus.innerText = message;
        locationStatus.style.color = ok ? '#46b450' : '#dc3232';
    }

    if (btnRegisterSite) {
        btnRegisterSite.addEventListener('click', function () {
            btnRegisterSite.disabled = true;
            setSiteStatus('Registrando...', true);

            fetch(mrg_admin_vars.ajax_url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    action: 'mrg_register_site',
                    nonce: mrg_admin_vars.nonce
                })
            })
                .then(function (res) {
                    return res.json();
                })
                .then(function (data) {
                    if (data.success) {
                        if (siteTokenInput) {
                            siteTokenInput.value = data.data.site_token;
                        }
                        setSiteStatus(data.data.message, true);
                    } else {
                        setSiteStatus('Error: ' + data.data, false);
                    }
                })
                .catch(function () {
                    setSiteStatus('Error al conectar con WordPress.', false);
                })
                .finally(function () {
                    btnRegisterSite.disabled = false;
                });
        });
    }

    if (btnConnectGoogle) {
        btnConnectGoogle.addEventListener('click', function () {
            btnConnectGoogle.disabled = true;
            setSiteStatus('Preparando conexion con Google...', true);

            fetch(mrg_admin_vars.ajax_url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    action: 'mrg_start_google_oauth',
                    nonce: mrg_admin_vars.nonce
                })
            })
                .then(function (res) {
                    return res.json();
                })
                .then(function (data) {
                    if (data.success && data.data.authorization_url) {
                        window.location.href = data.data.authorization_url;
                    } else {
                        setSiteStatus('Error: ' + data.data, false);
                    }
                })
                .catch(function () {
                    setSiteStatus('Error al conectar con WordPress.', false);
                })
                .finally(function () {
                    btnConnectGoogle.disabled = false;
                });
        });
    }

    if (btnLoadLocations) {
        btnLoadLocations.addEventListener('click', function () {
            btnLoadLocations.disabled = true;
            setLocationStatus('Cargando fichas...', true);

            fetch(mrg_admin_vars.ajax_url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    action: 'mrg_load_google_locations',
                    nonce: mrg_admin_vars.nonce
                })
            })
                .then(function (res) {
                    return res.json();
                })
                .then(function (data) {
                    if (!data.success || !data.data.locations || !locationsSelect) {
                        setLocationStatus('Error: ' + data.data, false);
                        return;
                    }

                    locationsSelect.innerHTML = '';
                    data.data.locations.forEach(function (location) {
                        const option = document.createElement('option');
                        option.value = JSON.stringify(location);
                        option.textContent = location.place_name || location.location_id;
                        locationsSelect.appendChild(option);
                    });

                    locationsSelect.style.display = 'inline-block';
                    if (btnSaveLocation) {
                        btnSaveLocation.style.display = 'inline-block';
                    }
                    setLocationStatus('Selecciona una ficha y guardala.', true);
                })
                .catch(function () {
                    setLocationStatus('Error al conectar con WordPress.', false);
                })
                .finally(function () {
                    btnLoadLocations.disabled = false;
                });
        });
    }

    if (btnSaveLocation) {
        btnSaveLocation.addEventListener('click', function () {
            if (!locationsSelect || !locationsSelect.value) {
                setLocationStatus('Selecciona una ficha primero.', false);
                return;
            }

            const selectedLocation = JSON.parse(locationsSelect.value);
            btnSaveLocation.disabled = true;
            setLocationStatus('Guardando ficha...', true);

            fetch(mrg_admin_vars.ajax_url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    action: 'mrg_save_google_location',
                    nonce: mrg_admin_vars.nonce,
                    account_id: selectedLocation.account_id || '',
                    location_id: selectedLocation.location_id || '',
                    place_name: selectedLocation.place_name || ''
                })
            })
                .then(function (res) {
                    return res.json();
                })
                .then(function (data) {
                    if (data.success) {
                        setLocationStatus(data.data.message, true);
                        setTimeout(function () {
                            location.reload();
                        }, 1200);
                    } else {
                        setLocationStatus('Error: ' + data.data, false);
                    }
                })
                .catch(function () {
                    setLocationStatus('Error al conectar con WordPress.', false);
                })
                .finally(function () {
                    btnSaveLocation.disabled = false;
                });
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

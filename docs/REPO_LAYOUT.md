# Estructura del repo

Este repositorio mezcla dos piezas que deben vivir separadas pero coordinadas:

- **Raiz del repo**: plugin de WordPress.
- **`backend/`**: servicio Python/FastAPI del plugin.
- **`dist/`**: ZIPs de publicacion del plugin.
- **`docs/`**: documentacion interna del proyecto.

La pieza de scraping real vive en otro repo:

- **Repo externo**: `google-reviews-scraper-pro`
- **VPS**: `/opt/supu/services/google-reviews-scraper`

## Arquitectura actual

- WordPress llama a `http://scraper.supufactory.es`.
- Nginx en el VPS reenvia a `http://127.0.0.1:8000`.
- El backend del plugin corre como `google-reviews.service`.
- El backend trabaja en modo `upstream`.
- El upstream real es el scraper en `http://127.0.0.1:8001`.
- El scraper real usa Selenium + Chromium en el VPS.

## Que va en cada sitio

- `mis-resenas-de-google.php`: bootstrap del plugin.
- `includes/`: logica PHP del plugin.
- `assets/`: CSS y JS del plugin.
- `templates/`: plantillas PHP/HTML.
- `backend/`: `app.py`, `service.py`, `schemas.py`, `requirements.txt`.
- `dist/`: paquetes ZIP finales.

## Que no se debe subir

- `backend/.venv/`
- `backend/__pycache__/`
- archivos temporales de Python
- ZIPs generados en `dist/`

## Regla practica

- Si lo usa WordPress, va en la raiz o en `includes/`.
- Si lo usa el VPS, va en `backend/`.
- Si es un entregable final, va en `dist/`.

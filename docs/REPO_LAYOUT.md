# Estructura del repo

Este repositorio mezcla dos piezas coordinadas:

- Raiz del repo: plugin de WordPress.
- `backend/`: servicio Python/FastAPI que importa reseñas.
- `docs/`: documentacion interna.
- `dist/`: ZIPs de publicacion del plugin.

## Arquitectura actual

- WordPress llama a `http://scraper.supufactory.es`.
- Nginx en el VPS reenvia a `http://127.0.0.1:8000`.
- El backend del plugin corre como `google-reviews.service`.
- El endpoint estable es `POST /v1/import-reviews`.
- El proveedor recomendado es `google_business_profile`.

## Proveedores del backend

- `backend/providers/google_business_profile.py`: API oficial de Google Business Profile.
- `backend/providers/apify.py`: integracion preparada para Apify.
- `backend/providers/selenium_legacy.py`: scraper Selenium antiguo como fallback.
- `backend/providers/demo.py`: datos de prueba.
- `backend/service.py`: selecciona proveedor con `MRG_REVIEW_PROVIDER`.

## Selenium legado

La pieza Selenium real vive fuera de este repo:

- Local: `C:\Users\Equipo\Desktop\google-reviews-scraper-pro`
- VPS: `/opt/supu/services/google-reviews-scraper`

No debe ser el camino principal porque Google cambia el DOM y rompe selectores.

## Que va en cada sitio

- `mis-resenas-de-google.php`: bootstrap del plugin.
- `includes/`: logica PHP del plugin.
- `assets/`: CSS y JS del plugin.
- `templates/`: plantillas PHP/HTML.
- `backend/`: API FastAPI y proveedores.
- `dist/`: paquetes ZIP finales.

## Que no se debe subir

- `backend/.venv/`
- `backend/__pycache__/`
- archivos temporales de Python
- ZIPs generados en `dist/`
- tokens o claves en `.env`

## Regla practica

- Si lo usa WordPress, va en la raiz o en `includes/`.
- Si lo ejecuta el VPS, va en `backend/`.
- Si es secreto, va en variables de entorno del servidor, nunca en Git.

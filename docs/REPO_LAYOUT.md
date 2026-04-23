# Estructura del repo

Este repositorio mezcla dos piezas que deben vivir separadas pero coordinadas:

- **Raíz del repo**: plugin de WordPress.
- **`backend/`**: servicio Python/FastAPI que hace de scraper/importador.
- **`dist/`**: ZIPs de publicación del plugin.
- **`docs/`**: documentación interna del proyecto.

## Qué va en cada sitio

- `mis-resenas-de-google.php`: bootstrap del plugin.
- `includes/`: lógica PHP del plugin.
- `assets/`: CSS y JS del plugin.
- `templates/`: plantillas PHP/HTML.
- `backend/`: `app.py`, `service.py`, `schemas.py`, `requirements.txt`.
- `dist/`: paquetes ZIP finales.

## Qué no se debe subir

- `backend/.venv/`
- `backend/__pycache__/`
- archivos temporales de Python
- ZIPs generados en `dist/`

## Regla práctica

- Si lo usa WordPress, va en la raíz o en `includes/`.
- Si lo usa el VPS, va en `backend/`.
- Si es un entregable final, va en `dist/`.


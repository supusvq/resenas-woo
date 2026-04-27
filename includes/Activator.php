<?php
namespace MRG;

if (!defined('ABSPATH')) {
    exit;
}

class Activator
{
    public static function activate()
    {
        Database::create_tables();

        $defaults = [
            'place_id' => '',
            'maps_url' => '',
            'scraper_service_url' => 'https://scraper.supufactory.es',
            'remote_sync_consent' => 0,
            'review_target_url' => '',
            'theme' => 'dark',
            'default_stars' => 'all',
            'reviews_limit' => 6,
            'cache_duration' => 24,
            'google_rating' => '5.0',
            'google_stars_header' => 5,
            'google_reviews_total' => 0,
            'last_sync' => '',
            'last_sync_timestamp' => '',
            'last_sync_datetime' => '',
            'slider_mode' => 'auto',
            'slider_speed' => 0.6,
            'enable_review_requests' => 0,
            'send_delay_days' => 0,
            'email_subject' => '¿Nos dejas tu reseña sobre tu pedido {numero_pedido}?',
            'from_name' => get_bloginfo('name'),
            'from_email' => get_option('admin_email'),
            'reply_to' => get_option('admin_email'),
            'email_company_name' => get_bloginfo('name'),
            'email_review_button_text' => 'Escribir reseña',
            'email_review_intro_text' => 'Tu opinión nos ayuda a seguir mejorando y a que otros clientes conozcan nuestro trabajo.',
            'footer_privacy_email' => get_option('admin_email'),
            'footer_privacy_url' => get_home_url() . '/politica-privacidad',
            'email_template' => self::get_default_email_template(),
        ];

        if (!get_option('mrg_settings')) {
            add_option('mrg_settings', $defaults);
        }
    }

    public static function get_default_email_template()
    {
        return '<div style="margin: 0; padding: 30px 12px; background-color: #f5f5f7; font-family: Arial, Helvetica, sans-serif;">
<table role="presentation" border="0" width="100%" cellspacing="0" cellpadding="0">
<tbody>
<tr>
<td align="center">
<table style="max-width: 600px; background-color: #ffffff; border-radius: 10px; overflow: hidden;" role="presentation" border="0" width="100%" cellspacing="0" cellpadding="0">
<tbody><!-- Header -->
<tr>
<td style="padding: 32px 30px 18px 30px;" align="center"><img style="border: 0; margin: 0 auto 18px auto;" src="https://upload.wikimedia.org/wikipedia/commons/2/2f/Google_2015_logo.svg" alt="Google" width="86" />
<div style="font-size: 12px; line-height: 1.4; letter-spacing: 1.6px; color: #86868b; text-transform: uppercase; font-weight: bold;">{nombre_empresa}</div>
</td>
</tr>
<!-- Body -->
<tr>
<td style="padding: 0 34px 10px 34px; text-align: center;" align="center"><br />
<div style="font-size: 28px; line-height: 1; margin: 0 0 16px 0;">⭐⭐⭐⭐⭐</div>
<div style="font-size: 20px; line-height: 1.2; font-weight: bold; color: #1d1d1f; margin: 0 0 16px 0;">Hola, {nombre_cliente}</div>
<div style="font-size: 17px; line-height: 1.65; color: #424245; margin: 0 0 10px 0;">Gracias por confiar en <strong>{nombre_empresa}</strong>.</div>
<div style="font-size: 17px; line-height: 1.65; color: #424245; margin: 0 0 26px 0;">{texto_intro_resena}</div>
<!-- Highlight box -->
<table style="margin: 0 0 28px 0;" role="presentation" border="0" width="100%" cellspacing="0" cellpadding="0">
<tbody>
<tr>
<td style="background-color: #f5f5f7; border: 1px solid #e5e5e7; border-radius: 16px; padding: 18px 20px; text-align: center;">
<div style="font-size: 18px; line-height: 1.5; color: #1d1d1f; font-weight: 600; margin: 0 0 6px 0;">¿Nos dejas tu reseña en Google?</div>
<div style="font-size: 14px; line-height: 1.5; color: #6e6e73; margin: 0;">Solo te llevará unos segundos</div>
</td>
</tr>
</tbody>
</table>
<!-- Button -->
<table style="margin: 0 auto 14px auto;" role="presentation" border="0" cellspacing="0" cellpadding="0">
<tbody>
<tr>
<td style="padding: 8px 0;" align="center">
<table border="0" cellspacing="0" cellpadding="0">
<tbody>
<tr>
<td style="background-color: #135e96; border-radius: 8px; padding: 20px 44px 20px 44px;" align="center"><a style="padding: 20px 44px 20px 44px; font-size: 15px; font-weight: bold; color: #ffffff; text-decoration: none; border-radius: 8px; font-family: Arial, Helvetica, sans-serif; white-space: nowrap; letter-spacing: 1px; text-transform: uppercase;" href="{enlace_resena}" target="_blank" rel="noopener"> ✍️   {texto_boton_resena} </a></td>
</tr>
</tbody>
</table>
</td>
</tr>
</tbody>
</table>
<div style="font-size: 13px; line-height: 1.5; color: #86868b; margin: 0 0 30px 0;">Haz clic en el botón y cuéntanos tu experiencia</div>
</td>
</tr>
<!-- Divider -->
<tr>
<td style="padding: 0 34px 0 34px;">
<table role="presentation" border="0" width="100%" cellspacing="0" cellpadding="0">
<tbody>
<tr>
<td style="background-color: #e5e5e7; font-size: 0; line-height: 0;" height="1"> </td>
</tr>
</tbody>
</table>
</td>
</tr>
<!-- Order info -->
<tr>
<td style="padding: 24px 34px 8px 34px;">
<table role="presentation" border="0" width="100%" cellspacing="0" cellpadding="0">
<tbody>
<tr>
<td style="padding: 0 10px 0 0; border-right: 1px solid #e5e5e7;" align="center" width="50%">
<div style="font-size: 11px; line-height: 1.4; color: #86868b; text-transform: uppercase; letter-spacing: 1px; margin: 0 0 6px 0;">Pedido</div>
<div style="font-size: 15px; line-height: 1.5; color: #1d1d1f; font-weight: bold; margin: 0;">{numero_pedido}</div>
</td>
<td style="padding: 0 0 0 10px;" align="center" width="50%">
<div style="font-size: 11px; line-height: 1.4; color: #86868b; text-transform: uppercase; letter-spacing: 1px; margin: 0 0 6px 0;">Fecha</div>
<div style="font-size: 15px; line-height: 1.5; color: #1d1d1f; font-weight: bold; margin: 0;">{fecha_pedido}</div>
</td>
</tr>
</tbody>
</table>
</td>
</tr>
<!-- Footer -->
<tr>
<td style="padding: 22px 34px 30px 34px;" align="center">
<div style="font-size: 13px; line-height: 1.6; color: #86868b;">Gracias por tu tiempo · {nombre_empresa}</div>
<div style="font-size:11px; line-height:1.6; color:#9ca3af; margin-top:20px;">
Recibes este correo porque has sido cliente de {nombre_empresa}. Tus datos se tratan conforme al 
Reglamento (UE) 2016/679 (RGPD) y la Ley Orgánica 3/2018 (LOPDGDD) con la finalidad de gestionar 
la relación comercial y solicitar tu valoración sobre nuestros servicios. Puedes ejercer tus derechos 
de acceso, rectificación, supresión, oposición o limitación del tratamiento enviando un email a 
{correo_empresa}. Más información en nuestra política de privacidad: {url_politica_privacidad}.
</div>
</td>
</tr>
</tbody>
</table>
</td>
</tr>
</tbody>
</table>
</div>';
    }
}

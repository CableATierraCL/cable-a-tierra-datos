<?php
/**
 * Band footer: update time, sources and the full, always visible MapBiomas citation (plain text).
 *
 * @var array<string, mixed> $view
 */

defined('ABSPATH') || exit;
?>
<footer class="cat-band__foot">
<p><?php echo esc_html((string) $view['updated']); ?></p>
<p>Fuente: <a href="<?php echo esc_url((string) $view['sourceUrl']); ?>">NoFires (nofires-latam.vercel.app)</a>. Focos: detecciones satelitales de calor (NASA FIRMS).</p>
<?php /* Citations are only supplied when a burned figure is shown in some card. */ ?>
<?php foreach ($view['citations'] as $citation) : ?>
<p class="cat-band__cite">Superficie quemada: <?php echo esc_html((string) $citation); ?></p>
<?php endforeach; ?>
</footer>

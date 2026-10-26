<?php
/**
 * Detail section: jump links, one panel per region and the shared sources. Panels arrive already
 * rendered (escaped) by region-panel.php; everything else is plain text escaped here.
 *
 * @var array<string, mixed> $view
 * @var callable $partial
 */

defined('ABSPATH') || exit;
?>
<section class="cat-detail alignwide" data-cat-tabs="" data-default="biobio" aria-label="Datos por región">
<ul class="cat-detail__nav" aria-label="Regiones">
<?php foreach ($view['nav'] as $link) : ?>
<li><a href="<?php echo esc_url((string) $link['href']); ?>"><?php echo esc_html((string) $link['name']); ?></a></li>
<?php endforeach; ?>
</ul>
<?php foreach ($view['panels'] as $panel) : ?>
<?php echo $panel; // Already escaped by region-panel.php. ?>
<?php endforeach; ?>
<?php if ($view['footer'] !== null) : ?>
<footer class="cat-detail__foot">
<p>Índice de riesgo, no probabilidad de incendio.</p>
<p>Fuente: <a href="<?php echo esc_url((string) $view['footer']['sourceUrl']); ?>">NoFires (nofires-latam.vercel.app)</a>. Focos: detecciones satelitales de calor (NASA FIRMS).</p>
<?php /* Citations are only supplied when a burned value is displayed in some panel. */ ?>
<?php foreach ($view['footer']['citations'] as $citation) : ?>
<p class="cat-detail__cite">Superficie quemada: <?php echo esc_html((string) $citation); ?></p>
<?php endforeach; ?>
</footer>
<?php endif; ?>
</section>

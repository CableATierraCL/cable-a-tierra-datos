<?php
/**
 * Home band section. Values come from BandRenderer as plain text; escape at every output.
 *
 * @var array<string, mixed> $view
 * @var callable $partial
 */

defined('ABSPATH') || exit;
?>
<section class="cat-data cat-band" id="datos" aria-labelledby="cat-band-title">
<div class="cat-band__inner">
<header class="cat-band__head">
<p class="cat-kicker">Datos · Monitoreo NoFires</p>
<h2 class="cat-data__title" id="cat-band-title">El territorio, medido.</h2>
<?php if ($view['headline'] !== null) : ?>
<p class="cat-band__summary"><?php echo esc_html($view['headline']); ?></p>
<?php endif; ?>
</header>
<?php if ($view['cards'] === []) : ?>
<p class="cat-band__empty">Datos no disponibles por el momento</p>
<?php else : ?>
<div class="cat-band__regions">
<?php foreach ($view['cards'] as $card) : ?>
<?php echo $partial('region-card', $card); // Already escaped by the partial. ?>
<?php endforeach; ?>
</div>
<p class="cat-band__note">Índice de riesgo, no probabilidad de incendio.<?php if ($view['methodologyUrl'] !== '') : ?> <a href="<?php echo esc_url($view['methodologyUrl']); ?>">Cómo se calcula</a><?php endif; ?></p>
<?php endif; ?>
<?php if ($view['ctaUrl'] !== '') : ?>
<div class="wp-block-buttons cat-buttons-light"><div class="wp-block-button is-style-outline"><a class="wp-block-button__link wp-element-button" href="<?php echo esc_url($view['ctaUrl']); ?>">Explorar los datos</a></div></div>
<?php endif; ?>
<?php if ($view['footer'] !== null) : ?>
<?php echo $partial('footer', $view['footer']); // Already escaped by the partial. ?>
<?php endif; ?>
</div>
</section>

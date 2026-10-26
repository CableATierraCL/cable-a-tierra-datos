<?php
/**
 * One region card. Same skeleton in every state; unavailable regions keep name and message only.
 *
 * @var array<string, mixed> $view
 */

defined('ABSPATH') || exit;

$slug = (string) $view['slug'];

/** One labelled figure. A null value is "no data", never zero. */
$stat = static function (string $label, ?string $value, string $class): void {
?>
<dl class="<?php echo esc_attr($class); ?>">
<div class="cat-region__stat">
<dt><?php echo esc_html($label); ?></dt>
<dd><?php if ($value === null) : ?><span aria-hidden="true">—</span> <span class="cat-region__na">Dato no disponible</span><?php else : ?><?php echo esc_html($value); ?><?php endif; ?></dd>
</div>
</dl>
<?php
};
?>
<article class="cat-region" aria-labelledby="<?php echo esc_attr('cat-region-' . $slug); ?>">
<header class="cat-region__head">
<h3 class="cat-region__name" id="<?php echo esc_attr('cat-region-' . $slug); ?>"><?php echo esc_html((string) $view['name']); ?></h3>
<?php if ($view['unavailable']) : ?>
</header>
<p class="cat-region__empty">Datos no disponibles por el momento</p>
<?php else : ?>
<?php if ($view['stale']) : ?>
<p class="cat-region__flag">Datos desactualizados</p>
<p class="cat-region__asof"><?php echo esc_html((string) $view['asof']); ?></p>
<?php endif; ?>
</header>
<?php $stat((string) $view['firmsLabel'], $view['firms'], 'cat-region__stats'); ?>
<div class="cat-region__dist">
<p class="cat-region__dist-title" id="<?php echo esc_attr('cat-dist-' . $slug); ?>">Comunas por nivel de riesgo <span>(<?php echo esc_html((string) $view['classified']); ?>)</span></p>
<div class="cat-bar" aria-hidden="true">
<?php foreach ($view['levels'] as $level) : ?>
<span class="<?php echo esc_attr('cat-bar__seg cat-level--' . $level['slug']); ?>" style="<?php echo esc_attr('--n:' . (int) $level['n']); ?>"></span>
<?php endforeach; ?>
</div>
<ul class="cat-legend" aria-labelledby="<?php echo esc_attr('cat-dist-' . $slug); ?>">
<?php foreach ($view['levels'] as $level) : ?>
<li class="cat-legend__item"><span class="<?php echo esc_attr('cat-legend__key cat-level--' . $level['slug']); ?>" aria-hidden="true"></span><?php echo esc_html($level['name']); ?> <span class="cat-legend__n"><?php echo esc_html((string) $level['n']); ?></span><span class="cat-band__sr"> comunas</span></li>
<?php endforeach; ?>
</ul>
</div>
<?php $stat((string) $view['burnedLabel'], $view['burned'], 'cat-region__stats cat-region__stats--secondary'); ?>
<?php endif; ?>
</article>

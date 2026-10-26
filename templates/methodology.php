<?php
/**
 * Methodology section. Static: renders only what Methodology\Facts::view() hands over (plain text,
 * escaped here). No page title (the page owns the h1) and no data access.
 *
 * @var array<string, mixed> $view
 */

defined('ABSPATH') || exit;
?>
<section class="cat-detail cat-detail--method alignwide" id="metodologia" aria-labelledby="metodologia-title">
<h2 class="cat-detail__title" id="metodologia-title"><?php echo esc_html((string) $view['title']); ?></h2>
<p><?php echo esc_html((string) $view['intro']); ?></p>
<ol class="cat-detail__levels">
<?php foreach ($view['levels'] as $level) : ?>
<li><strong><?php echo esc_html((string) $level['name']); ?></strong>. <?php echo esc_html((string) $level['lead']); ?>
<?php if ($level['items'] !== []) : ?>
<ul class="cat-detail__list">
<?php foreach ($level['items'] as $item) : ?>
<li><?php echo esc_html((string) $item); ?></li>
<?php endforeach; ?>
</ul>
<?php endif; ?>
</li>
<?php endforeach; ?>
</ol>
<p><?php echo esc_html((string) $view['scoreNote']); ?></p>
<h3 class="cat-detail__sub"><?php echo esc_html((string) $view['driversTitle']); ?></h3>
<dl class="cat-detail__terms">
<?php foreach ($view['drivers'] as $driver) : ?>
<dt><?php echo esc_html((string) $driver['label']); ?></dt>
<dd><?php echo esc_html((string) $driver['meaning']); ?></dd>
<?php endforeach; ?>
</dl>
<p><?php echo esc_html((string) $view['driversNote']); ?></p>
<h3 class="cat-detail__sub">De dónde salen los datos</h3>
<ul class="cat-detail__list">
<?php foreach ($view['sources'] as $line) : ?>
<li><?php echo esc_html((string) $line); ?></li>
<?php endforeach; ?>
</ul>
<h3 class="cat-detail__sub">Cuándo se actualizan</h3>
<ul class="cat-detail__list">
<?php foreach ($view['cadence'] as $line) : ?>
<li><?php echo esc_html((string) $line); ?></li>
<?php endforeach; ?>
</ul>
<h3 class="cat-detail__sub">Límites que conviene conocer</h3>
<ul class="cat-detail__list">
<?php foreach ($view['limitations'] as $line) : ?>
<li><?php echo esc_html((string) $line); ?></li>
<?php endforeach; ?>
</ul>
<footer class="cat-detail__foot">
<p><?php echo esc_html((string) $view['attribution']['nofires']); ?> <a href="<?php echo esc_url((string) $view['attribution']['nofiresUrl']); ?>">NoFires (nofires-latam.vercel.app)</a>.</p>
<p><?php echo esc_html((string) $view['attribution']['firms']); ?></p>
<p class="cat-detail__cite">Superficie quemada: <?php echo esc_html((string) $view['attribution']['mapbiomas']); ?></p>
</footer>
</section>

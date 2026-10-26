<?php
/**
 * One region panel. Same skeleton in every state: id and heading always render, so anchors keep working.
 *
 * @var array<string, mixed> $view
 */

defined('ABSPATH') || exit;
?>
<section class="cat-detail__panel" id="<?php echo esc_attr((string) $view['id']); ?>" data-region="<?php echo esc_attr((string) $view['region']); ?>">
<h2 class="cat-detail__title"><?php echo esc_html((string) $view['name']); ?></h2>
<?php if ($view['unavailable']) : ?>
<p class="cat-detail__empty">Datos no disponibles por el momento</p>
<?php else : ?>
<p class="cat-detail__asof"><?php echo esc_html((string) $view['asof']); ?></p>
<?php if ($view['banner'] !== null) : ?>
<p class="cat-detail__flag" role="status"><?php echo esc_html((string) $view['banner']); ?></p>
<?php endif; ?>
<p class="cat-detail__coverage"><?php echo esc_html((string) $view['coverage']); ?></p>
<?php endif; ?>
<?php if ($view['extra'] !== '') : ?>
<div class="cat-detail__extra"><?php echo $view['extra']; // Captured from the hook and passed through wp_kses_post() by the renderer. ?></div>
<?php endif; ?>
<?php if (!$view['unavailable']) : ?>
<div class="cat-detail__scroll" role="region" tabindex="0" aria-labelledby="<?php echo esc_attr((string) $view['captionId']); ?>">
<table class="cat-detail__table">
<caption id="<?php echo esc_attr((string) $view['captionId']); ?>"><?php echo esc_html((string) $view['caption']); ?></caption>
<thead>
<tr>
<th scope="col">Comuna</th>
<th scope="col">Nivel</th>
<th scope="col">Causa</th>
<th scope="col" class="cat-detail__num"><?php echo esc_html((string) $view['firmsHeader']); ?></th>
<th scope="col" class="cat-detail__num"><?php echo esc_html((string) $view['burnedHeader']); ?></th>
</tr>
</thead>
<tbody>
<?php foreach ($view['rows'] as $row) : ?>
<tr>
<th scope="row"><?php echo esc_html((string) $row['name']); ?></th>
<td><span class="<?php echo esc_attr('cat-detail__sw cat-detail__sw--' . $row['swatch']); ?>" aria-hidden="true"></span><?php echo esc_html((string) $row['level']); ?></td>
<td><?php echo esc_html((string) $row['cause']); ?></td>
<td class="cat-detail__num"><?php echo esc_html((string) $row['firms']); ?></td>
<td class="cat-detail__num"><?php echo esc_html((string) $row['burned']); ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php endif; ?>
</section>

<?php
/**
 * Template Name: 会社概要
 * Template Post Type: page
 *
 * 会社概要ページ。各領域は「外観 > カスタマイズ > 固定ページ >
 * 会社概要 ページ」で設定します。
 *
 * @package jijipom
 */

get_header();

$jijipom_lead           = get_theme_mod( 'jijipom_company_lead', '' );
$jijipom_greet_heading  = get_theme_mod( 'jijipom_company_greeting_heading', __( 'ごあいさつ', 'jijipom' ) );
$jijipom_greet_text     = get_theme_mod( 'jijipom_company_greeting_text', '' );
$jijipom_greet_image    = get_theme_mod( 'jijipom_company_greeting_image', '' );
$jijipom_greet_name     = get_theme_mod( 'jijipom_company_greeting_name', '' );
$jijipom_ov_heading     = get_theme_mod( 'jijipom_company_overview_heading', __( '会社概要', 'jijipom' ) );
$jijipom_access_heading = get_theme_mod( 'jijipom_company_access_heading', __( 'アクセス', 'jijipom' ) );
$jijipom_access_addr    = get_theme_mod( 'jijipom_company_access_address', '' );
$jijipom_access_hours   = get_theme_mod( 'jijipom_company_access_hours', '' );
$jijipom_access_holiday = get_theme_mod( 'jijipom_company_access_holiday', '' );
$jijipom_map_url        = get_theme_mod( 'jijipom_company_map_url', '' );

// 内容が入っている概要行だけ集める。
$jijipom_rows = array();
for ( $i = 1; $i <= 8; $i++ ) {
	$label = get_theme_mod( "jijipom_company_row{$i}_label", '' );
	$value = get_theme_mod( "jijipom_company_row{$i}_value", '' );
	if ( '' !== trim( (string) $value ) ) {
		$jijipom_rows[] = array(
			'label' => $label,
			'value' => $value,
		);
	}
}
$jijipom_has_access = ( $jijipom_access_addr || $jijipom_access_hours || $jijipom_access_holiday || $jijipom_map_url );
?>

<div class="page-template page-company">
	<?php
	while ( have_posts() ) :
		the_post();
		?>

		<header class="page-template__header front-inner">
			<?php the_title( '<h1 class="page-template__title">', '</h1>' ); ?>
			<?php if ( $jijipom_lead ) : ?>
				<p class="page-template__lead"><?php echo nl2br( esc_html( $jijipom_lead ) ); ?></p>
			<?php endif; ?>
		</header>

		<?php if ( $jijipom_greet_text ) : ?>
			<section class="front-section company-greeting">
				<div class="front-inner">
					<?php if ( $jijipom_greet_heading ) : ?>
						<h2 class="front-section__heading"><?php echo esc_html( $jijipom_greet_heading ); ?></h2>
					<?php endif; ?>
					<div class="company-greeting__grid <?php echo $jijipom_greet_image ? 'has-image' : ''; ?>">
						<?php if ( $jijipom_greet_image ) : ?>
							<div class="company-greeting__media">
								<img src="<?php echo esc_url( $jijipom_greet_image ); ?>" alt="<?php echo esc_attr( $jijipom_greet_name ); ?>" loading="lazy">
							</div>
						<?php endif; ?>
						<div class="company-greeting__body">
							<p><?php echo nl2br( esc_html( $jijipom_greet_text ) ); ?></p>
							<?php if ( $jijipom_greet_name ) : ?>
								<p class="company-greeting__name"><?php echo esc_html( $jijipom_greet_name ); ?></p>
							<?php endif; ?>
						</div>
					</div>
				</div>
			</section>
		<?php endif; ?>

		<?php if ( ! empty( $jijipom_rows ) ) : ?>
			<section class="front-section front-section--muted company-overview">
				<div class="front-inner">
					<?php if ( $jijipom_ov_heading ) : ?>
						<h2 class="front-section__heading"><?php echo esc_html( $jijipom_ov_heading ); ?></h2>
					<?php endif; ?>
					<table class="company-overview__table">
						<tbody>
							<?php foreach ( $jijipom_rows as $row ) : ?>
								<tr>
									<th scope="row"><?php echo esc_html( $row['label'] ); ?></th>
									<td><?php echo nl2br( esc_html( $row['value'] ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</section>
		<?php endif; ?>

		<?php if ( trim( get_the_content() ) !== '' ) : ?>
			<section class="front-section">
				<div class="front-inner entry-content">
					<?php the_content(); ?>
				</div>
			</section>
		<?php endif; ?>

		<?php if ( $jijipom_has_access ) : ?>
			<section class="front-section company-access">
				<div class="front-inner">
					<?php if ( $jijipom_access_heading ) : ?>
						<h2 class="front-section__heading"><?php echo esc_html( $jijipom_access_heading ); ?></h2>
					<?php endif; ?>
					<?php if ( $jijipom_access_addr || $jijipom_access_hours || $jijipom_access_holiday ) : ?>
						<dl class="company-access__info">
							<?php if ( $jijipom_access_addr ) : ?>
								<dt><?php esc_html_e( '住所', 'jijipom' ); ?></dt>
								<dd><?php echo nl2br( esc_html( $jijipom_access_addr ) ); ?></dd>
							<?php endif; ?>
							<?php if ( $jijipom_access_hours ) : ?>
								<dt><?php esc_html_e( '営業時間', 'jijipom' ); ?></dt>
								<dd><?php echo esc_html( $jijipom_access_hours ); ?></dd>
							<?php endif; ?>
							<?php if ( $jijipom_access_holiday ) : ?>
								<dt><?php esc_html_e( '定休日', 'jijipom' ); ?></dt>
								<dd><?php echo esc_html( $jijipom_access_holiday ); ?></dd>
							<?php endif; ?>
						</dl>
					<?php endif; ?>
					<?php if ( $jijipom_map_url ) : ?>
						<div class="company-access__map">
							<iframe src="<?php echo esc_url( $jijipom_map_url ); ?>" width="100%" height="360" style="border:0;" allowfullscreen="" loading="lazy" referrerpolicy="no-referrer-when-downgrade" title="<?php esc_attr_e( '地図', 'jijipom' ); ?>"></iframe>
						</div>
					<?php endif; ?>
				</div>
			</section>
		<?php endif; ?>

	<?php endwhile; ?>
</div><!-- .page-company -->

<?php
get_footer();

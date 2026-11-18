# New feature plan

**Status:** planned, not started.

Two features are running today as must-use plugins on one client site, voltautogroup.sg, built on 2026-11-18. Both solve problems that will come up on other managed sites, so the plan is to rebuild them as toolkit modules that can be switched on per site.

| # | Feature | Mu-plugin today | Proposed module id | Needs |
|---|---|---|---|---|
| 1 | Leads screen for Editors, with Excel export | `voltauto-leads.php` | `elementor-leads` | Elementor Pro |
| 2 | Google Sheets rows stored exactly as typed | `voltauto-gsheet-raw-values.php` | `gsheet-raw-values` | GSheetConnector for Elementor Forms |

The full source of both mu-plugins, as deployed, is at the end of this file.

---

## 1. Leads screen for Editors (`elementor-leads`)

### The problem

Editors need to see website enquiries, but Elementor Pro keeps **Elementor → Submissions** for Administrators only. As of Elementor Pro 4.2:

- the Submissions menu item requires `manage_options`
- the REST controllers the screen loads data from, `elementor/v1/form-submissions` and `elementor/v1/forms`, check `current_user_can( 'manage_options' )` in their permission callbacks
- there's no filter to change either

We tried granting `manage_options` to Editors only on that screen and its two REST routes, using a `user_has_cap` filter. It worked, but it was too clever. While the screen was open, the sidebar showed admin-only menu items, and it depended on Elementor's internal routes not changing. It was removed at the client's request.

The replacement is a separate, read-only screen that reads Elementor's submission tables directly.

### What the mu-plugin does today

- **Menu:** a top-level **Lead** item (`admin.php?page=voltauto-leads`, dashicon `groups`, position 26). It uses the capability `edit_others_pages`, so Editors and Administrators see it.
- **Data:**
  - Rows come from `{prefix}e_submissions` where `type = 'submission'` and `status <> 'trash'`, newest first.
  - Field values come from `{prefix}e_submissions_values` (`submission_id`, `key`, `value`), fetched in one query per page.
- **Column labels:** these come from Elementor Pro's form snapshot, post meta `__elementor_forms_snapshot` on the page holding the form. It's a JSON list of forms, each with `fields[] = {id, type, label}`. Columns follow the form's own field order. A value whose key isn't in any snapshot still gets a column, labelled from its key, so no data is hidden.
- **Table:**
  - Columns are Date, then each form field, then Form, then Page (the referring page, linked).
  - Dates use the site's timezone and date/time formats, converted from `created_at_gmt`.
  - Emails show as `mailto:` links and phone numbers as `tel:` links.
  - Uploads show as "File 1, File 2…" links. Elementor stores these as comma-separated URLs.
  - Textarea answers keep their line breaks and wrap at about 420px.
- **Search and paging:** a search box matches any field value (`LIKE`, escaped with `esc_like`). There are 20 rows per page, using `paginate_links()`.
- **Export:**
  - **Export to Excel (.xlsx)** posts to `admin-post.php?action=voltauto_leads_export` with the nonce `voltauto_leads_export`. It respects the current search.
  - The file is written with `ZipArchive` as a minimal SpreadsheetML package: content types, rels, workbook, styles and one sheet. The header row is bold and frozen.
  - **Every cell is an inline string (`t="inlineStr"`).** That's deliberate:
    - Excel never evaluates a value, so there's no CSV/formula injection.
    - Phone numbers keep a leading `+` or `0`.
    - Unicode text needs no BOM tricks.
  - CSV was rejected because Excel reads `+65 9123 4567` as a formula and drops leading zeros. Wrapping values as `="…"` or prefixing `'` is unreliable across Excel, Numbers, LibreOffice and Google Sheets.
- **Security:**
  - Both the page and the export check `edit_others_pages`, and the export also checks the nonce.
  - All output is escaped, and the search value goes through `$wpdb->prepare()`.
  - Logged out, the page redirects to login and the export returns HTTP 400. No `nopriv` handler is registered.
- **Read-only:** no delete, edit or mark-as-read.

### How it was tested

| Check | Result |
|---|---|
| Lint | Lints clean on PHP 7.4 and 8.3 |
| As the Editor | `current_user_can` is true, the menu is registered, the page lists every non-trashed submission (5 at the time) and the trashed one is excluded |
| Search | A match returns 1 row. A miss shows "No leads match your search." and hides the export button |
| Export structure | A valid zip whose XML parts are all well-formed |
| Export in ExcelJS | Opens, the header is bold and frozen, and phone cells are text with leading `0` and `+` intact |
| Logged out | The page gives a 302 to login, and the export gives a 400 with no data |

Not tested: a real browser session as the Editor, because the test environment couldn't create one. Ask the site owner to click through once after the module ships.

### Module design

- **Registry entry** in `WPPDT_Modules::register_modules()`:

  ```php
  'elementor-leads' => array(
  	'label'        => __( 'Leads (Elementor form submissions)', 'wp-pro-devs-toolkit' ),
  	'description'  => __( 'A read-only Leads screen for Editors listing Elementor form submissions, with search and Excel export.', 'wp-pro-devs-toolkit' ),
  	'file'         => WPPDT_PLUGIN_DIR . 'modules/elementor-leads/class-wppdt-elementor-leads.php',
  	'class'        => 'WPPDT_Elementor_Leads',
  	'requires'     => __( 'Elementor Pro', 'wp-pro-devs-toolkit' ),
  	'is_available' => static function () {
  		return defined( 'ELEMENTOR_PRO_VERSION' );
  	},
  ),
  ```

- **Files:**
  - `modules/elementor-leads/class-wppdt-elementor-leads.php` extends `WPPDT_Module`. Its `init()` registers `admin_menu` and `admin_post_wppdt_leads_export`.
  - `modules/elementor-leads/class-wppdt-leads-query.php` holds the query, the column building from the snapshot, and the date formatting.
  - `includes/class-wppdt-xlsx-writer.php` is a small generic writer, reusable by other modules: `new WPPDT_Xlsx_Writer( $sheet_name )->add_row( array $cells, bool $bold = false )->save( $path )`.
- **Slugs:** use the menu slug `wppdt-leads` and the admin-post action `wppdt_leads_export`, not the mu-plugin's `voltauto-*` names.
- **Settings:** store them in the option `wppdt_elementor_leads` and show them in the module's card on the settings screen.

  | Setting | Default |
  |---|---|
  | Menu label | "Leads" (the client site uses "Lead") |
  | Who can see it | Editors and Administrators (`edit_others_pages`). Optionally Administrators only, or a custom capability |
  | Forms to include | All, or chosen forms by name or element ID |
  | Hidden columns | None. For example, hide uploads |
  | Rows per page | 20 |

- **Ideas beyond the mu-plugin**, each only if a site needs it:
  - a form filter dropdown, once a site has more than one form
  - a date-range filter, also applied to the export
  - an unread count on the menu and a "mark as read" action, using Elementor's `is_read` column
  - a single-lead view for long messages
  - trashing leads, for Administrators only, by setting `status = 'trash'` the way Elementor does
- **Migrating a site that has the mu-plugin:**
  1. Delete `wp-content/mu-plugins/voltauto-leads.php`.
  2. Enable the module.
  3. Set the menu label to "Lead" to match what the client already uses.

  Add a guard: if `function_exists( 'voltauto_leads_query' )`, show an admin notice and skip registering the menu, so there aren't two menus.

### Open questions and limits

- **Elementor 4 atomic forms (`e-form`):** do they write to `e_submissions`? The GSheetConnector code notes that atomic forms don't fire `elementor_pro/forms/new_record`. Check this before advertising support.
- **Global widget forms:** the snapshot sits on the template, not the page the visitor used. Look up labels from the template ID as well.
- **Large sites:** the export loads every matching row into memory. That's fine up to a few thousand rows. Beyond that, write rows in chunks.
- **Timezone:** times follow the site's timezone setting. Some sites are left on UTC.

---

## 2. Google Sheets rows stored exactly as typed (`gsheet-raw-values`)

### The problem

**GSheetConnector – Elementor Forms Google Sheet Connector** (free, `gsheetconnector-for-elementor-forms`, tested at 1.4.0) appends each submission with:

```
POST https://sheets.googleapis.com/v4/spreadsheets/{id}/values/{tab}!A1:append?valueInputOption=USER_ENTERED
```

`USER_ENTERED` makes Google parse every value as if a person typed it:

- `+65 9123 4567` becomes `#ERROR!`, because it's parsed as a formula
- a message starting with `=`, `+`, `-` or `@` becomes a formula. That's an injection risk: `=IMPORTXML(…)` would run inside the sheet
- phone numbers lose their leading zeros

Things that don't help:

- Formatting the sheet's columns as plain text. We tested this, and `USER_ENTERED` still parses formulas.
- The plugin has no filter on the row data or on the value input option.

### What the mu-plugin does today

It hooks `pre_http_request`. When the URL starts with `https://sheets.googleapis.com/v4/spreadsheets/` and contains `:append?valueInputOption=USER_ENTERED`, it reissues the same request with `valueInputOption=RAW` and returns that response. Everything else passes through untouched. The reissued URL no longer matches, so it can't loop.

The trade-off: with `RAW`, dates and times are stored as text, for example "November 18, 2026" and "2:43 am". They read fine, but sorting the date column is alphabetical rather than chronological.

### How it was tested

- Through GSheetConnector's own handler (`GSC_Elementor_Integration::send_form_submission_to_google_sheets_feed`, called with a fake record):
  - before: `+65 0000 0000` became `#ERROR!`
  - after: `+65 9123 4567` and `=1+1 should stay text` were both stored literally
- A real visitor submission kept `0177…` with its leading zero.
- The test rows were removed afterwards.

### Module design

- **Registry entry:** id `gsheet-raw-values`, with `requires` set to "GSheetConnector for Elementor Forms" and `is_available` checking `defined( 'GS_CONN_ELE_ROOT' ) || class_exists( 'GSC_Elementor_Free' )`.
- **Class:** `modules/gsheet-raw-values/class-wppdt-gsheet-raw-values.php`. Its `init()` adds the same `pre_http_request` filter.
- **Possible setting: "Keep dates as dates".** Instead of switching to `RAW`, keep `USER_ENTERED` and rewrite the JSON body so every value except the plugin's date and time keys gets a leading `'`, Google's own "treat as text" marker, which Sheets hides. That keeps a sortable date column and still neutralises formulas. It's more fragile because it depends on the body shape, so default to `RAW`.
- **Scope:** check whether GSheetConnector's other free plugins (Contact Form 7, WPForms, Gravity Forms, WooCommerce) append the same way. If they do, the module covers them too, and the label becomes "Google Sheets: store values exactly as typed".
- **Health check:** the fix depends on GSheetConnector's URL string. On the module's settings card, scan the installed plugin's `lib/google-sheets.php` for `valueInputOption=USER_ENTERED`.
  - If it's gone, show "GSheetConnector changed how it writes rows; this fix may no longer apply."
  - If the plugin already uses `RAW`, show "not needed".
- **Migration:** delete `wp-content/mu-plugins/voltauto-gsheet-raw-values.php`, then enable the module. If both are briefly active, that's harmless: the first one rewrites the URL and the second no longer matches.

### Related GSheetConnector notes, for the module docs

- **Header row:** the plugin fills a row by matching the sheet's **row-1 headers** to the form's field labels exactly. If row 1 is empty, it silently writes nothing. Extra header names it fills by itself: `Entry ID`, `Date`, `Entry Date`, `Submission Date`, `date`, `time`, and `UTM campaign`. `Entry ID` is a cached `MAX(id)` and isn't reliable.
- **How feeds are stored:**
  - Each feed is a postmeta row on the form's page, keyed by the feed name, with the value `{type: gscele_form_feeds, element_id}`.
  - That row's `meta_id` is then used as a post ID for `gscele_form_feeds` (sheet-name, sheet-id, sheet-tab-name, tab-id) and `gselef_status` (1 means on).
- **Known bug in 1.4.0:** `gsc_elementor_after_save_settings`, hooked on `elementor/editor/after_save`, runs `iterate_data()` on the decoded `actions` request. Each Elementor save or autosave then logs three `foreach() argument must be of type array|object` warnings from `elementor/includes/db.php:268`. It's harmless log noise. Report it upstream. The module could optionally unhook it.

---

## Appendix: full source as deployed (2026-11-18)

Both files are in `wp-content/mu-plugins/` on the client site.

### `voltauto-leads.php`

```php
<?php
/**
 * Plugin Name: Volt Auto – Leads
 * Description: Adds a "Lead" menu for Editors and Administrators that lists Elementor form submissions (read-only) and exports them to Excel (.xlsx).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const VOLTAUTO_LEADS_CAP      = 'edit_others_pages'; // Editors and Administrators.
const VOLTAUTO_LEADS_PAGE     = 'voltauto-leads';
const VOLTAUTO_LEADS_PER_PAGE = 20;

/**
 * Submissions that aren't trashed, newest first, each with its field values.
 *
 * @param string $search Text to look for in any field value.
 * @param int    $limit  0 for all.
 * @param int    $offset Rows to skip.
 * @return array{0: object[], 1: int} Rows and total count, or null rows if Elementor Pro's tables are missing.
 */
function voltauto_leads_query( string $search = '', int $limit = 0, int $offset = 0 ): array {
	global $wpdb;

	$subs = $wpdb->prefix . 'e_submissions';
	$vals = $wpdb->prefix . 'e_submissions_values';

	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $subs ) ) !== $subs ) {
		return array( null, 0 );
	}

	$where = "s.type = 'submission' AND s.status <> 'trash'";
	$args  = array();
	if ( '' !== $search ) {
		$where .= " AND s.id IN ( SELECT submission_id FROM $vals WHERE value LIKE %s )";
		$args[] = '%' . $wpdb->esc_like( $search ) . '%';
	}

	$count_sql = "SELECT COUNT(*) FROM $subs s WHERE $where";
	$total     = (int) $wpdb->get_var( $args ? $wpdb->prepare( $count_sql, $args ) : $count_sql );

	$sql = "SELECT s.id, s.post_id, s.element_id, s.form_name, s.referer, s.referer_title, s.created_at_gmt FROM $subs s WHERE $where ORDER BY s.created_at_gmt DESC, s.id DESC";
	if ( $limit > 0 ) {
		$sql   .= ' LIMIT %d OFFSET %d';
		$args[] = $limit;
		$args[] = $offset;
	}
	$rows = $args ? $wpdb->get_results( $wpdb->prepare( $sql, $args ) ) : $wpdb->get_results( $sql );

	$values = array();
	if ( $rows ) {
		$ids = implode( ',', array_map( 'intval', wp_list_pluck( $rows, 'id' ) ) );
		foreach ( $wpdb->get_results( "SELECT submission_id, `key`, value FROM $vals WHERE submission_id IN ($ids) ORDER BY id" ) as $v ) {
			$values[ $v->submission_id ][ $v->key ] = $v->value;
		}
	}
	foreach ( $rows as $row ) {
		$row->values = $values[ $row->id ] ?? array();
	}

	return array( $rows, $total );
}

/**
 * Field columns for the given rows: field id => [label, type], in the forms' own field order.
 *
 * Labels come from the form snapshot Elementor Pro keeps on each page; keys
 * that are no longer in a form still get a column so no data is hidden.
 *
 * @param object[] $rows Rows from voltauto_leads_query().
 * @return array<string, array{label: string, type: string}>
 */
function voltauto_leads_columns( array $rows ): array {
	$columns = array();

	foreach ( array_unique( array_map( 'intval', wp_list_pluck( $rows, 'post_id' ) ) ) as $post_id ) {
		$forms = json_decode( (string) get_post_meta( $post_id, '__elementor_forms_snapshot', true ), true );
		foreach ( is_array( $forms ) ? $forms : array() as $form ) {
			foreach ( $form['fields'] ?? array() as $field ) {
				if ( isset( $field['id'] ) && ! isset( $columns[ $field['id'] ] ) ) {
					$columns[ $field['id'] ] = array(
						'label' => '' !== ( $field['label'] ?? '' ) ? $field['label'] : ucfirst( str_replace( '_', ' ', $field['id'] ) ),
						'type'  => $field['type'] ?? 'text',
					);
				}
			}
		}
	}

	foreach ( $rows as $row ) {
		foreach ( array_keys( $row->values ) as $key ) {
			if ( ! isset( $columns[ $key ] ) ) {
				$columns[ $key ] = array(
					'label' => ucfirst( str_replace( '_', ' ', $key ) ),
					'type'  => 'text',
				);
			}
		}
	}

	return $columns;
}

/** Submission time in the site's timezone. */
function voltauto_leads_date( string $gmt, string $format = '' ): string {
	$format = $format ? $format : get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
	return (string) wp_date( $format, strtotime( $gmt . ' UTC' ) );
}

add_action(
	'admin_menu',
	static function (): void {
		add_menu_page( 'Leads', 'Lead', VOLTAUTO_LEADS_CAP, VOLTAUTO_LEADS_PAGE, 'voltauto_leads_render_page', 'dashicons-groups', 26 );
	}
);

/** The Lead admin screen. */
function voltauto_leads_render_page(): void {
	if ( ! current_user_can( VOLTAUTO_LEADS_CAP ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to access this page.' ) );
	}

	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only filters.
	$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
	$paged  = max( 1, isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1 );
	// phpcs:enable

	list( $rows, $total ) = voltauto_leads_query( $search, VOLTAUTO_LEADS_PER_PAGE, ( $paged - 1 ) * VOLTAUTO_LEADS_PER_PAGE );

	$export_url = wp_nonce_url(
		add_query_arg(
			array(
				'action' => 'voltauto_leads_export',
				's'      => $search,
			),
			admin_url( 'admin-post.php' )
		),
		'voltauto_leads_export'
	);
	?>
	<div class="wrap voltauto-leads">
		<h1 class="wp-heading-inline">Leads</h1>
		<?php if ( $total ) : ?>
			<a href="<?php echo esc_url( $export_url ); ?>" class="page-title-action">Export to Excel (.xlsx)</a>
		<?php endif; ?>
		<hr class="wp-header-end">

		<?php if ( null === $rows ) : ?>
			<div class="notice notice-warning"><p>Elementor Pro form submissions aren't available on this site.</p></div>
		<?php else : ?>
			<form method="get" class="search-form" style="margin: 12px 0;">
				<input type="hidden" name="page" value="<?php echo esc_attr( VOLTAUTO_LEADS_PAGE ); ?>">
				<p class="search-box">
					<label class="screen-reader-text" for="voltauto-leads-search">Search leads</label>
					<input type="search" id="voltauto-leads-search" name="s" value="<?php echo esc_attr( $search ); ?>">
					<input type="submit" class="button" value="Search leads">
				</p>
			</form>

			<p><?php echo esc_html( sprintf( _n( '%s lead', '%s leads', $total ), number_format_i18n( $total ) ) . ( '' !== $search ? ' matching "' . $search . '"' : '' ) ); ?></p>

			<?php if ( ! $rows ) : ?>
				<p><?php echo '' !== $search ? 'No leads match your search.' : 'No leads yet.'; ?></p>
			<?php else : ?>
				<?php $columns = voltauto_leads_columns( $rows ); ?>
				<style>
					.voltauto-leads table td { vertical-align: top; }
					.voltauto-leads .va-long { min-width: 220px; max-width: 420px; white-space: normal; word-break: break-word; }
					.voltauto-leads .va-date { white-space: nowrap; }
				</style>
				<div style="overflow-x: auto;">
					<table class="widefat striped">
						<thead>
							<tr>
								<th>Date</th>
								<?php foreach ( $columns as $column ) : ?>
									<th><?php echo esc_html( $column['label'] ); ?></th>
								<?php endforeach; ?>
								<th>Form</th>
								<th>Page</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $rows as $row ) : ?>
								<tr>
									<td class="va-date"><?php echo esc_html( voltauto_leads_date( $row->created_at_gmt ) ); ?></td>
									<?php foreach ( $columns as $key => $column ) : ?>
										<td<?php echo 'textarea' === $column['type'] ? ' class="va-long"' : ''; ?>><?php voltauto_leads_cell( (string) ( $row->values[ $key ] ?? '' ), $column['type'] ); ?></td>
									<?php endforeach; ?>
									<td><?php echo esc_html( $row->form_name ); ?></td>
									<td>
										<?php if ( $row->referer ) : ?>
											<a href="<?php echo esc_url( $row->referer ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $row->referer_title ? $row->referer_title : $row->referer ); ?></a>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
				<?php
				$pages = (int) ceil( $total / VOLTAUTO_LEADS_PER_PAGE );
				if ( $pages > 1 ) {
					echo '<div class="tablenav"><div class="tablenav-pages">';
					echo wp_kses_post(
						paginate_links(
							array(
								'base'    => add_query_arg( 'paged', '%#%' ),
								'format'  => '',
								'current' => $paged,
								'total'   => $pages,
							)
						)
					);
					echo '</div></div>';
				}
				?>
			<?php endif; ?>
		<?php endif; ?>
	</div>
	<?php
}

/** One table cell: links for email, phone and uploaded files, line breaks kept for long text. */
function voltauto_leads_cell( string $value, string $type ): void {
	if ( '' === $value ) {
		return;
	}

	switch ( $type ) {
		case 'email':
			echo '<a href="' . esc_url( 'mailto:' . $value ) . '">' . esc_html( $value ) . '</a>';
			break;
		case 'tel':
			echo '<a href="' . esc_url( 'tel:' . preg_replace( '/[^0-9+]/', '', $value ) ) . '">' . esc_html( $value ) . '</a>';
			break;
		case 'upload':
			$links = array();
			foreach ( array_filter( array_map( 'trim', explode( ',', $value ) ) ) as $i => $url ) {
				$links[] = '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">File ' . ( $i + 1 ) . '</a>';
			}
			echo implode( ', ', $links ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
			break;
		default:
			echo nl2br( esc_html( $value ) );
	}
}

add_action(
	'admin_post_voltauto_leads_export',
	static function (): void {
		if ( ! current_user_can( VOLTAUTO_LEADS_CAP ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.' ), 403 );
		}
		check_admin_referer( 'voltauto_leads_export' );

		$search      = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		list( $rows ) = voltauto_leads_query( $search );
		$file        = voltauto_leads_xlsx( $rows ? $rows : array() );

		while ( ob_get_level() ) {
			ob_end_clean();
		}
		nocache_headers();
		header( 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
		header( 'Content-Disposition: attachment; filename="voltauto-leads-' . wp_date( 'Y-m-d' ) . '.xlsx"' );
		header( 'Content-Length: ' . filesize( $file ) );
		readfile( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		wp_delete_file( $file );
		exit;
	}
);

/**
 * Writes the leads to a temporary .xlsx file and returns its path.
 *
 * Every cell is an inline string, so Excel keeps values exactly as typed
 * (leading "+" or zeros in phone numbers) and never evaluates them as formulas.
 *
 * @param object[] $rows Rows from voltauto_leads_query().
 * @return string Path to the file; the caller deletes it.
 */
function voltauto_leads_xlsx( array $rows ): string {
	$columns = voltauto_leads_columns( $rows );
	$header  = array_merge( array( 'Date' ), array_values( wp_list_pluck( $columns, 'label' ) ), array( 'Form', 'Page' ) );

	$col = static function ( int $i ): string {
		$letters = '';
		for ( $n = $i + 1; $n > 0; $n = intdiv( $n - 1, 26 ) ) {
			$letters = chr( 65 + ( $n - 1 ) % 26 ) . $letters;
		}
		return $letters;
	};
	$cell = static function ( string $ref, $text, bool $bold = false ): string {
		$text = wp_check_invalid_utf8( (string) $text, true );
		$text = (string) preg_replace( '/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $text );
		return '<c r="' . $ref . '" t="inlineStr"' . ( $bold ? ' s="1"' : '' ) . '><is><t xml:space="preserve">' . htmlspecialchars( $text, ENT_XML1 | ENT_QUOTES, 'UTF-8' ) . '</t></is></c>';
	};

	$xml_rows = array();
	$lines    = array( $header );
	foreach ( $rows as $row ) {
		$line = array( voltauto_leads_date( $row->created_at_gmt, 'Y-m-d H:i' ) );
		foreach ( array_keys( $columns ) as $key ) {
			$line[] = $row->values[ $key ] ?? '';
		}
		$line[] = $row->form_name;
		$line[] = $row->referer;
		$lines[] = $line;
	}
	foreach ( $lines as $r => $line ) {
		$cells = '';
		foreach ( $line as $c => $value ) {
			$cells .= $cell( $col( $c ) . ( $r + 1 ), $value, 0 === $r );
		}
		$xml_rows[] = '<row r="' . ( $r + 1 ) . '">' . $cells . '</row>';
	}

	$head  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
	$main  = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
	$rels  = 'http://schemas.openxmlformats.org/package/2006/relationships';
	$odrel = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

	$parts = array(
		'[Content_Types].xml'        => $head . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>',
		'_rels/.rels'                => $head . '<Relationships xmlns="' . $rels . '"><Relationship Id="rId1" Type="' . $odrel . '/officeDocument" Target="xl/workbook.xml"/></Relationships>',
		'xl/workbook.xml'            => $head . '<workbook xmlns="' . $main . '" xmlns:r="' . $odrel . '"><sheets><sheet name="Leads" sheetId="1" r:id="rId1"/></sheets></workbook>',
		'xl/_rels/workbook.xml.rels' => $head . '<Relationships xmlns="' . $rels . '"><Relationship Id="rId1" Type="' . $odrel . '/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="' . $odrel . '/styles" Target="styles.xml"/></Relationships>',
		'xl/styles.xml'              => $head . '<styleSheet xmlns="' . $main . '"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs></styleSheet>',
		'xl/worksheets/sheet1.xml'   => $head . '<worksheet xmlns="' . $main . '"><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><cols><col min="1" max="' . count( $header ) . '" width="24" customWidth="1"/></cols><sheetData>' . implode( '', $xml_rows ) . '</sheetData></worksheet>',
	);

	$file = wp_tempnam( 'voltauto-leads.xlsx' );
	$zip  = new ZipArchive();
	if ( true !== $zip->open( $file, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
		wp_die( 'Could not create the Excel file.' );
	}
	foreach ( $parts as $name => $content ) {
		$zip->addFromString( $name, $content );
	}
	$zip->close();

	return $file;
}
```

### `voltauto-gsheet-raw-values.php`

```php
<?php
/**
 * Plugin Name: Volt Auto – Google Sheets values as typed
 * Description: GSheetConnector appends Elementor form entries with valueInputOption=USER_ENTERED, so Google Sheets reads answers such as "+65 9123 4567" or "=..." as formulas and shows #ERROR!. This switches its append requests to RAW so every value is stored exactly as submitted.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) {
		if (
			false !== $pre
			|| 0 !== strpos( $url, 'https://sheets.googleapis.com/v4/spreadsheets/' )
			|| false === strpos( $url, ':append?valueInputOption=USER_ENTERED' )
		) {
			return $pre;
		}

		return wp_remote_request( str_replace( 'valueInputOption=USER_ENTERED', 'valueInputOption=RAW', $url ), $args );
	},
	10,
	3
);
```

<?php
/**
 * Portal-App (eigene Seite, unabhängig vom Theme).
 *
 * @var string $view login | app | denied
 */

defined( 'ABSPATH' ) || exit;

$v   = fn( $f ) => GLP_VERSION . '.' . (int) @filemtime( GLP_DIR . $f );
$css = array( 'assets/css/fonts.css', 'assets/css/base.css', 'assets/css/portal.css' );
$g   = 'M170.1,69c0-6.6-5.7-11.1-12.3-11.1s-12.9,4.8-17.7,10.2c-5.7,6.6-11.1,12.3-21,13.8-11.1-3.9-24.3-6-39-6C34.5,75.9,1.2,96,1.2,130.2c0,20.1,12.6,33.3,31.5,41.1C15.6,179.1,0,191.1,0,209.7c0,12.3,8.7,34.8,50.4,34.8h56.7c12,0,18.6,3.6,18.6,12.9,0,8.7-5.7,18.9-22.5,18.9-3.3,0-6.9,1.5-6.9,5.7,0,4.5,5.1,6.3,10.5,6.3,40.8,0,60.9-25.8,60.9-49.2,0-26.1-18.9-42.6-63.3-42.6h-59.1c-9.9,0-13.5-3.3-13.5-8.1,0-5.1,4.2-9.6,9.6-14.1,11.1,3,23.4,4.5,36.3,4.5,44.1,0,78-17.7,78-49.2,0-18.6-9.3-33-25.2-42.3,26.7-1.5,39.6-6.6,39.6-18.3ZM80.1,167.7c-15.3,0-22.5-17.7-22.5-42s6.3-37.8,19.8-37.8c15.6,0,22.2,18,22.2,41.1,0,24.9-6.3,38.7-19.5,38.7Z';
$mark = '<svg viewBox="0 0 172 289" aria-hidden="true"><path d="' . esc_attr( $g ) . '"/></svg>';
?>
<!doctype html>
<html lang="de">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
	<meta name="robots" content="noindex, nofollow">
	<meta name="theme-color" content="#0A0B0A">
	<title>Member-Portal · <?php echo esc_html( get_bloginfo( 'name' ) ); ?></title>
	<link rel="icon" href="<?php echo esc_url( GLP_URL . 'assets/img/favicon.svg' ); ?>" type="image/svg+xml">
	<?php foreach ( $css as $f ) : ?>
		<link rel="stylesheet" href="<?php echo esc_url( GLP_URL . $f . '?ver=' . $v( $f ) ); ?>">
	<?php endforeach; ?>
</head>
<body class="glp glp--<?php echo esc_attr( $view ); ?>">

<?php if ( $view !== 'app' ) : ?>
	<section class="login">
		<div class="login__img"><img src="<?php echo esc_url( GLP_URL . 'assets/img/login.jpg' ); ?>" alt=""></div>
		<div class="login__box">
			<a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="login__mark logo" aria-label="Zur Website"><?php echo $mark; // phpcs:ignore ?></a>
			<span class="meta">Member-Portal · Intern</span>
			<h1 class="display-l">Back&shy;stage</h1>
			<?php if ( $view === 'denied' ) : ?>
				<p>Du bist eingeloggt, hast aber noch keinen Zugang zum Portal. Bitte einen Admin, dir die Rolle <strong>Bandmitglied</strong> zu geben.</p>
				<a class="btn" href="<?php echo esc_url( wp_logout_url( glp_portal_url() ) ); ?>">Abmelden</a>
			<?php else : ?>
				<?php
				wp_login_form( array(
					'redirect'       => glp_portal_url(),
					'label_username' => 'Benutzername oder E-Mail',
					'label_password' => 'Passwort',
					'label_remember' => 'Angemeldet bleiben',
					'label_log_in'   => 'Einloggen ↗',
					'remember'       => true,
					'value_remember' => true,
				) );
				?>
				<a class="meta link" href="<?php echo esc_url( wp_lostpassword_url( glp_portal_url() ) ); ?>">Passwort vergessen?</a>
			<?php endif; ?>
		</div>
	</section>
<?php else : ?>

<div class="app" id="app">
	<aside class="side">
		<a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="side__mark logo" aria-label="Zur Website"><?php echo $mark; // phpcs:ignore ?></a>
		<nav class="side__nav" aria-label="Portal">
			<button data-view="home" class="is-active"><span class="meta">01</span>Übersicht</button>
			<button data-view="kalender"><span class="meta">02</span>Kalender</button>
			<button data-view="studio"><span class="meta">03</span>Studio</button>
			<button data-view="pool"><span class="meta">04</span>Pressepool</button>
			<button data-view="pr"><span class="meta">05</span>PR</button>
		</nav>
		<div class="side__user">
			<span class="avatar" id="me-avatar"></span>
			<div>
				<span class="label" id="me-name"></span>
				<a class="meta link" href="<?php echo esc_url( admin_url( 'profile.php' ) ); ?>">Profil</a> ·
				<a class="meta link" href="<?php echo esc_url( wp_logout_url( glp_portal_url() ) ); ?>">Abmelden</a>
			</div>
		</div>
	</aside>

	<main class="main">
		<div class="loading meta" id="loading">Lade Daten …</div>

		<!-- ===== ÜBERSICHT ===== -->
		<section class="view" data-view="home" hidden>
			<header class="view__head">
				<span class="meta" id="today"></span>
				<h1 class="display-l">Servus,<br><span id="hello-name"></span></h1>
			</header>
			<div class="grid-home">
				<article class="card card--kante">
					<div class="kante">Nächster Termin</div>
					<div class="card__body" id="home-next"></div>
				</article>
				<article class="card">
					<span class="meta">Debütalbum · Aufnahmestand</span>
					<p class="big-num"><b id="home-album">0</b>%</p>
					<div class="bar"><i id="home-album-bar"></i></div>
					<span class="meta meta--hell" id="home-album-sub"></span>
					<button class="btn btn--klein" data-goto="studio">Zum Studio <span class="pfeil">↗</span></button>
				</article>
				<article class="card card--wide">
					<div class="card__row"><span class="meta">Deine offenen Aufgaben</span><span class="meta" id="todo-count"></span></div>
					<ol class="todo" id="home-todo"></ol>
				</article>
				<article class="card card--wide">
					<div class="card__row"><span class="meta">Zuletzt passiert</span></div>
					<ol class="log" id="home-log"></ol>
				</article>
			</div>
		</section>

		<!-- ===== KALENDER ===== -->
		<section class="view" data-view="kalender" hidden>
			<header class="view__head view__head--row">
				<div><span class="meta">02 — Termine</span><h1 class="display-l">Kalen&shy;der</h1></div>
				<div class="actions">
					<button class="btn btn--klein" id="ical-toggle">Kalender-Abo <span class="pfeil">↗</span></button>
					<button class="btn btn--klein btn--patina" id="event-toggle">+ Termin</button>
				</div>
			</header>
			<div class="panel" id="ical-panel" hidden>
				<p class="klein">Mit diesem persönlichen Link erscheinen alle Proben und Termine automatisch in deinem Handy-Kalender (iPhone: <em>Einstellungen → Kalender → Accounts → Kalenderabo hinzufügen</em>, Google: <em>Andere Kalender → Per URL</em>). Nicht weitergeben.</p>
				<div class="copy"><input id="ical-url" readonly><button class="btn btn--klein" id="ical-copy">Kopieren</button><button class="btn btn--klein" id="ical-reset" title="Neuen Link erzeugen, alter wird ungültig">Neu erzeugen</button></div>
			</div>
			<form class="panel event-form" id="event-form" hidden>
				<label class="feld"><span class="meta">Art</span><select name="type" id="ev-type"><option value="studio">Studio-Session</option><option value="konzert">Konzert</option><option value="termin">Sonstiger Termin</option></select></label>
				<label class="feld feld--breit"><span class="meta">Titel</span><input name="title" id="ev-title" required placeholder="z. B. Drums · Mikrofilet"></label>
				<label class="feld"><span class="meta">Datum</span><input type="date" name="date" id="ev-date" required></label>
				<label class="feld"><span class="meta">Uhrzeit</span><input type="time" name="time" id="ev-time" value="19:00"></label>
				<label class="feld feld--breit"><span class="meta">Ort</span><input name="place" id="ev-place" placeholder="z. B. bei Valentin"></label>
				<fieldset class="feld feld--breit"><legend class="meta">Wer ist dabei?</legend><div class="who-pick" id="ev-who"></div></fieldset>
				<div class="feld--breit actions"><button class="btn btn--patina" type="submit">Termin speichern</button><button class="btn" type="button" id="event-cancel">Abbrechen</button></div>
			</form>
			<div class="filter" id="cal-filter">
				<button class="chip is-on" data-type="alle">Alle</button>
				<button class="chip" data-type="probe">Proben</button>
				<button class="chip" data-type="studio">Studio</button>
				<button class="chip" data-type="konzert">Konzerte</button>
				<button class="chip" data-type="termin">Sonstiges</button>
			</div>
			<ol class="cal" id="cal"></ol>
		</section>

		<!-- ===== STUDIO ===== -->
		<section class="view" data-view="studio" hidden>
			<header class="view__head view__head--row">
				<div><span class="meta">03 — Aufnahmeplan</span><h1 class="display-l">Studio<br>Album</h1></div>
				<div class="kpis">
					<div class="kpi"><span class="meta">Album gesamt</span><b id="kpi-album">0%</b></div>
					<div class="kpi"><span class="meta">Neue Songs</span><b id="kpi-new">0%</b></div>
					<div class="kpi"><span class="meta">Songs fertig</span><b id="kpi-done">0/0</b></div>
					<div class="kpi"><span class="meta">Offene Schritte</span><b id="kpi-open">0</b></div>
				</div>
			</header>
			<div class="album-bar"><div class="bar bar--dick"><i id="studio-bar"></i></div></div>
			<div class="toolbar">
				<div class="filter" id="member-filter"></div>
				<div class="filter">
					<span class="meta">Sortierung:</span>
					<button class="chip is-on" data-sort="track">Tracklist</button>
					<button class="chip" data-sort="progress">Fortschritt</button>
				</div>
			</div>
			<div class="legend meta">
				<span><i class="cell-dot s0"></i>offen</span>
				<span><i class="cell-dot s1"></i>aufgenommen / in Arbeit</span>
				<span><i class="cell-dot s2"></i>editiert / fertig</span>
				<span><i class="cell-dot sna"></i>nicht nötig</span>
				<span class="legend__hint">Klick auf ein Feld = nächster Status · Klick auf Song = Details</span>
			</div>
			<div class="matrix-wrap"><table class="matrix" id="matrix"></table></div>
			<form class="add-song" id="add-song">
				<input id="new-song" placeholder="Neuer Song (Arbeitstitel)" maxlength="190" aria-label="Neuer Song">
				<button class="btn btn--klein" type="submit">+ Song anlegen</button>
			</form>
			<div class="studio-bottom">
				<article class="card">
					<div class="card__row"><span class="meta">Aufnahmeplan · nächste Studio-Sessions</span><button class="link meta" data-goto="kalender">+ im Kalender ↗</button></div>
					<ol class="sessions" id="sessions"></ol>
				</article>
				<article class="card">
					<div class="card__row"><span class="meta">So wird gerechnet</span></div>
					<p class="klein">Jeder Schritt hat ein Gewicht. <b>Aufgenommen</b> zählt halb, <b>editiert</b> ganz. Was ein Song nicht braucht, setzt ihr auf <b>nicht nötig</b>, dann fällt es aus der Rechnung.<?php if ( current_user_can( 'manage_options' ) ) : ?> Schritte, Gewichte und Zuständige: <a class="link" href="<?php echo esc_url( admin_url( 'options-general.php?page=ghostlike-portal' ) ); ?>">Einstellungen ↗</a><?php endif; ?></p>
					<ol class="weights" id="weights"></ol>
				</article>
			</div>
		</section>

		<!-- ===== PRESSEPOOL ===== -->
		<section class="view" data-view="pool" hidden>
			<header class="view__head"><span class="meta">04 — In Planung</span><h1 class="display-l">Presse&shy;pool</h1></header>
			<p class="coming meta meta--hell">Kommt als nächste Ausbaustufe: Upload, Kategorien nach Ära, ZIP-Download, Haken „öffentlich“ → erscheint automatisch im Pressekit der Website.</p>
		</section>

		<!-- ===== PR ===== -->
		<section class="view" data-view="pr" hidden>
			<header class="view__head"><span class="meta">05 — In Planung</span><h1 class="display-l">PR-Zen&shy;trale</h1></header>
			<p class="coming meta meta--hell">Kommt danach: Kontakte &amp; Verlauf, Kampagnen mit Vorlagen im CI-Look, persönliche Release-Links pro Empfänger (gesehen / gehört / geladen), Follow-up-Erinnerungen.</p>
		</section>
	</main>
</div>

<!-- ===== SONG-DETAIL ===== -->
<div class="drawer" id="drawer" hidden>
	<div class="drawer__bg" data-close></div>
	<aside class="drawer__panel" role="dialog" aria-modal="true" aria-labelledby="d-title">
		<header class="drawer__head">
			<div><span class="meta" id="d-nr"></span><h2 class="display-m" id="d-title"></h2></div>
			<button class="btn btn--klein" data-close>Schließen ✕</button>
		</header>
		<div class="drawer__prog"><div class="bar"><i id="d-bar"></i></div><b id="d-pct"></b></div>
		<div class="drawer__meta">
			<label class="feld feld--breit"><span class="meta">Titel</span><input data-field="title" id="d-f-title"></label>
			<label class="feld"><span class="meta">Track-Nr.</span><input data-field="nr" id="d-f-nr" inputmode="numeric"></label>
			<label class="feld"><span class="meta">BPM</span><input data-meta="bpm" id="d-m-bpm" inputmode="numeric" placeholder="–"></label>
			<label class="feld"><span class="meta">Tonart</span><input data-meta="key" id="d-m-key" placeholder="–"></label>
			<label class="feld"><span class="meta">Tuning</span><input data-meta="tuning" id="d-m-tuning" placeholder="z. B. Drop A"></label>
			<label class="feld feld--breit"><span class="meta">Demo / Session-Link</span><input data-meta="link" id="d-m-link" placeholder="https://…"></label>
		</div>
		<div class="drawer__flags">
			<label><input type="checkbox" data-flag="single" id="d-fl-single"><span>Single-Kandidat</span></label>
			<label><input type="checkbox" data-flag="video" id="d-fl-video"><span>Musikvideo geplant</span></label>
			<label><input type="checkbox" data-field="album" id="d-f-album"><span>Auf dem Album</span></label>
			<label><input type="checkbox" data-field="released" id="d-f-released"><span>Schon veröffentlicht</span></label>
		</div>
		<ol class="steps" id="d-steps"></ol>
		<label class="feld"><span class="meta">Notizen / Was fehlt noch?</span><textarea data-meta="note" id="d-m-note" rows="3"></textarea></label>
		<div class="drawer__foot"><span class="meta" id="d-saved"></span><button class="btn btn--klein btn--gefahr" id="d-delete">Song löschen</button></div>
	</aside>
</div>

<div class="toast meta meta--hell" id="toast" role="status"></div>

<script>window.GLP = <?php echo wp_json_encode( array( 'rest' => esc_url_raw( rest_url( GLP_NS . '/' ) ), 'nonce' => wp_create_nonce( 'wp_rest' ) ) ); ?>;</script>
<script src="<?php echo esc_url( GLP_URL . 'assets/js/portal.js?ver=' . $v( 'assets/js/portal.js' ) ); ?>"></script>
<?php endif; ?>
</body>
</html>

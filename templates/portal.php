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
	<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">
	<meta name="robots" content="noindex, nofollow">
	<meta name="theme-color" content="#0A0B0A">
	<title>Member-Portal · <?php echo esc_html( get_bloginfo( 'name' ) ); ?></title>
	<link rel="icon" href="<?php echo esc_url( GLP_URL . 'assets/img/favicon.svg' ); ?>" type="image/svg+xml">
	<link rel="icon" href="<?php echo esc_url( GLP_URL . 'assets/img/app/favicon-32.png' ); ?>" sizes="32x32" type="image/png">
	<?php // Zum Home-Bildschirm: eigene App, die direkt im Portal startet ?>
	<link rel="apple-touch-icon" href="<?php echo esc_url( GLP_URL . 'assets/img/app/apple-touch-icon.png' ); ?>">
	<link rel="manifest" href="<?php echo esc_url( add_query_arg( 'glp_manifest', 1, glp_portal_url() ) ); ?>">
	<meta name="apple-mobile-web-app-title" content="GL Portal">
	<meta name="apple-mobile-web-app-capable" content="yes">
	<meta name="mobile-web-app-capable" content="yes">
	<meta name="apple-mobile-web-app-status-bar-style" content="black">
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
			<?php if ( $view === 'invite' ) : ?>
				<p>Willkommen, <strong><?php echo esc_html( trim( $invite_user->first_name ) ?: $invite_user->display_name ); ?></strong>! Leg dein Passwort fest, danach bist du direkt im Portal. Dein Benutzername ist <strong><?php echo esc_html( $invite_user->user_login ); ?></strong> (oder deine E-Mail).</p>
				<?php if ( $invite_error ) : ?><p class="fehler"><?php echo esc_html( $invite_error ); ?></p><?php endif; ?>
				<form method="post" id="loginform">
					<?php wp_nonce_field( 'glp_set_password' ); ?>
					<input type="text" name="log" value="<?php echo esc_attr( $invite_user->user_login ); ?>" autocomplete="username" hidden>
					<p><label for="pass1">Neues Passwort (mind. 10 Zeichen)</label><input type="password" id="pass1" name="pass1" minlength="10" required autocomplete="new-password"></p>
					<p><label for="pass2">Passwort wiederholen</label><input type="password" id="pass2" name="pass2" minlength="10" required autocomplete="new-password"></p>
					<p><button class="button-primary" type="submit">Passwort speichern &amp; loslegen ↗</button></p>
				</form>
			<?php elseif ( $view === 'invite-invalid' ) : ?>
				<p>Dieser Einladungslink ist abgelaufen oder wurde schon benutzt.</p>
				<p class="meta">Schon ein Passwort gesetzt? Dann einfach einloggen. Sonst bitte um eine neue Einladung.</p>
				<a class="btn btn--patina" href="<?php echo esc_url( glp_portal_url() ); ?>">Zum Login ↗</a>
			<?php elseif ( $view === 'denied' ) : ?>
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
			<button data-view="home" class="is-active"><svg class="side__ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 11l9-7 9 7v9a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z"/></svg><span class="meta">01</span><span class="side__long">Übersicht</span><span class="side__short">Start</span></button>
			<button data-view="kalender"><svg class="side__ic" viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="1"/><path d="M3 10h18M8 3v4M16 3v4"/></svg><span class="meta">02</span><span class="side__long">Kalender</span><span class="side__short">Kalender</span></button>
			<button data-view="studio"><svg class="side__ic" viewBox="0 0 24 24" aria-hidden="true"><rect x="9" y="3" width="6" height="11" rx="3"/><path d="M5 11a7 7 0 0 0 14 0M12 18v3M8 21h8"/></svg><span class="meta">03</span><span class="side__long">Studio</span><span class="side__short">Studio</span></button>
			<button data-view="kasse"><svg class="side__ic" viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="6" width="18" height="13" rx="1"/><path d="M3 10h18M16 15h2"/></svg><span class="meta">04</span><span class="side__long">Bandkasse</span><span class="side__short">Kasse</span></button>
			<button data-view="pool"><svg class="side__ic" viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4" width="18" height="16" rx="1"/><circle cx="9" cy="10" r="2"/><path d="M21 16l-5-5-9 9"/></svg><span class="meta">05</span><span class="side__long">Pressepool</span><span class="side__short">Presse</span></button>
			<button data-view="pr"><svg class="side__ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 10v4h4l6 4V6L7 10zM17 9a4 4 0 0 1 0 6M19.5 6.5a8 8 0 0 1 0 11"/></svg><span class="meta">06</span><span class="side__long">PR</span><span class="side__short">PR</span></button>
		</nav>
		<div class="side__user">
			<button class="avatar avatar--knopf" id="me-avatar" data-goto="profil" title="Mein Profil" aria-label="Mein Profil"></button>
			<div>
				<span class="label" id="me-name"></span>
				<button class="meta link" data-goto="profil">Profil</button> ·
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
					<button class="btn btn--klein" id="ical-toggle">Kalender abonnieren <span class="pfeil">↗</span></button>
					<button class="btn btn--klein btn--patina" id="event-toggle">+ Termin</button>
				</div>
			</header>
			<div class="panel" id="ical-panel" hidden>
				<p class="klein">Einmal abonnieren, danach erscheinen alle Proben und Termine automatisch in deinem Kalender und bleiben aktuell: Absagen und Änderungen (z. B. Treffen statt Probe) kommen von selbst nach, bei Apple und Outlook meist innerhalb einer Stunde, bei Google Kalender kann es bis zu einem Tag dauern. Der Link ist persönlich, bitte nicht weitergeben.</p>
				<div class="abo">
					<a class="btn btn--patina" id="abo-apple" href="#">Apple Kalender <span class="pfeil">↗</span></a>
					<a class="btn" id="abo-google" href="#" target="_blank" rel="noopener">Google Kalender <span class="pfeil">↗</span></a>
					<a class="btn" id="abo-outlook" href="#" target="_blank" rel="noopener">Outlook <span class="pfeil">↗</span></a>
				</div>
				<p class="meta">Apple: öffnet auf iPhone und Mac direkt den Abo-Dialog. Anderer Kalender? Link kopieren und dort „Kalender per URL abonnieren“ wählen.</p>
				<div class="copy"><input id="ical-url" readonly aria-label="Persönlicher Kalender-Link"><button class="btn btn--klein" id="ical-copy">Kopieren</button><button class="btn btn--klein" id="ical-reset" title="Neuen Link erzeugen, alter wird ungültig">Neu erzeugen</button></div>
			</div>
			<form class="panel event-form" id="event-form" hidden>
				<p class="feld--breit event-form__head meta" id="ev-head" hidden></p>
				<label class="feld"><span class="meta">Art</span><select name="type" id="ev-type"><option value="probe" hidden>Bandprobe</option><option value="treffen">Treffen / Meeting</option><option value="studio">Studio-Session</option><option value="konzert">Konzert</option><option value="termin">Sonstiger Termin</option></select></label>
				<label class="feld feld--breit"><span class="meta">Titel</span><input name="title" id="ev-title" required placeholder="z. B. Drums · Mikrofilet"></label>
				<label class="feld"><span class="meta">Datum</span><input type="date" name="date" id="ev-date" required></label>
				<label class="feld"><span class="meta">Uhrzeit</span><input type="time" name="time" id="ev-time" value="19:00"></label>
				<label class="feld feld--breit"><span class="meta">Ort</span><input name="place" id="ev-place" placeholder="z. B. bei Valentin"></label>
				<fieldset class="feld feld--breit"><legend class="meta">Wer ist dabei?</legend><div class="who-pick" id="ev-who"></div></fieldset>
				<fieldset class="feld feld--breit" id="ev-plan-box"><legend class="meta">Was wird aufgenommen? (optional)</legend><div class="plan-pick" id="ev-plan"></div></fieldset>
				<label class="feld feld--breit"><span class="meta">Notiz (optional)</span><textarea name="note" id="ev-note" rows="2" placeholder="z. B. Agenda: Setlist, Fotoshooting, Merch"></textarea></label>
				<div class="feld--breit actions"><button class="btn btn--patina" type="submit" id="ev-submit">Termin speichern</button><button class="btn" type="button" id="event-cancel">Abbrechen</button><button class="link meta" type="button" id="ev-reset" hidden>↺ Wieder normale Probe</button></div>
			</form>
			<div class="filter" id="cal-filter">
				<button class="chip is-on" data-type="alle">Alle</button>
				<button class="chip" data-type="probe">Proben</button>
				<button class="chip" data-type="studio">Studio</button>
				<button class="chip" data-type="konzert">Konzerte</button>
				<button class="chip" data-type="treffen">Treffen</button>
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
			<div class="studio-tabs" role="tablist" aria-label="Ansicht">
				<button class="studio-tab is-on" role="tab" aria-selected="true" data-studio="matrix">Tabelle</button>
				<button class="studio-tab" role="tab" aria-selected="false" data-studio="todos">Meine To-dos <span class="studio-tab__n" id="todo-n"></span></button>
			</div>

			<div id="studio-todos" hidden>
				<div class="toolbar">
					<div class="filter" id="todo-who"></div>
					<div class="filter">
						<span class="meta">Zeigen:</span>
						<button class="chip is-on" data-todo-filter="alle">Alles offen</button>
						<button class="chip" data-todo-filter="rec">Noch offen</button>
						<button class="chip" data-todo-filter="wip">In Arbeit</button>
						<button class="chip" data-todo-filter="edit">Editieren</button>
					</div>
				</div>
				<div class="todo-sum" id="todo-sum"></div>
				<div class="todo-songs" id="todo-songs"></div>
			</div>

			<div id="studio-matrix">
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
				<span><i class="cell-dot sw"></i>in Arbeit</span>
				<span><i class="cell-dot s1"></i>aufgenommen</span>
				<span><i class="cell-dot s2"></i>editiert / fertig</span>
				<span><i class="cell-dot sna"></i>nicht nötig</span>
				<span class="legend__hint">Klick auf ein Feld = nächster Status · Klick auf Song = Details · ⋮⋮ ziehen = Reihenfolge</span>
			</div>
			<div class="matrix-wrap"><table class="matrix" id="matrix"></table></div>
			<div class="cards" id="cards"></div>
			<form class="add-song" id="add-song">
				<input id="new-song" placeholder="Neuer Song (Arbeitstitel)" maxlength="190" aria-label="Neuer Song">
				<button class="btn btn--klein" type="submit">+ Song anlegen</button>
			</form>
			</div>
			<div class="studio-bottom">
				<article class="card">
					<div class="card__row"><span class="meta">Aufnahmeplan · nächste Studio-Sessions</span><button class="link meta" data-goto="kalender">+ im Kalender ↗</button></div>
					<ol class="sessions" id="sessions"></ol>
				</article>
				<article class="card">
					<div class="card__row"><span class="meta">So wird gerechnet</span></div>
					<p class="klein">Jeder Schritt hat ein Gewicht. <b>In Arbeit</b> zählt ein Viertel (Pre-Production die Hälfte), <b>aufgenommen</b> halb, <b>editiert/fertig</b> ganz. Was ein Song nicht braucht, setzt ihr auf <b>nicht nötig</b>, dann fällt es aus der Rechnung.<?php if ( current_user_can( 'manage_options' ) ) : ?> Schritte, Gewichte und Zuständige: <a class="link" href="<?php echo esc_url( admin_url( 'options-general.php?page=ghostlike-portal' ) ); ?>">Einstellungen ↗</a><?php endif; ?></p>
					<ol class="weights" id="weights"></ol>
				</article>
			</div>
			<details class="history card" id="history">
				<summary class="history__sum"><span class="meta">Historie</span><span class="label">Wer hat wann was geändert?</span><span class="history__chev" aria-hidden="true">▾</span></summary>
				<div class="history__bar">
					<label class="feld"><span class="meta">Song</span><select id="h-song"><option value="">Alle Songs</option></select></label>
					<div class="filter" id="h-who"></div>
				</div>
				<ol class="history__list" id="h-list"></ol>
				<button type="button" class="btn btn--klein" id="h-more" hidden>Ältere laden</button>
			</details>
		</section>

		<!-- ===== PROFIL ===== -->
		<section class="view" data-view="profil" hidden>
			<header class="view__head"><span class="meta">Dein Zugang</span><h1 class="display-l">Mein<br>Profil</h1></header>
			<div class="profil">
				<form class="card profil__card" id="pf-about">
					<span class="meta">Über dich</span>
					<label class="feld"><span class="meta">Vorname *</span><input id="pf-first" name="first" required maxlength="60" autocomplete="given-name"></label>
					<label class="feld"><span class="meta">Nachname</span><input id="pf-last" name="last" maxlength="60" autocomplete="family-name"></label>
					<label class="feld"><span class="meta">Instrumente / Rolle in der Band</span><input id="pf-role" name="role" maxlength="80" placeholder="z. B. Gitarre, Backing Vocals"></label>
					<label class="feld"><span class="meta">PayPal.me-Name (für die Bandkasse)</span><span class="prefix-input"><span class="meta">paypal.me/</span><input id="pf-paypal" name="paypal" maxlength="40" placeholder="deinname" autocomplete="off" autocapitalize="off"></span></label>
					<p class="klein">Dein Vorname wird im Portal angezeigt und für die Zuständigkeiten im Studio-Tool genutzt. Änderst du ihn, werden deine Zuständigkeiten automatisch mit umbenannt.</p>
					<button class="btn btn--patina" type="submit">Speichern</button>
				</form>

				<form class="card profil__card" id="pf-email">
					<span class="meta">E-Mail-Adresse</span>
					<label class="feld"><span class="meta">E-Mail</span><input id="pf-mail" name="email" type="email" required autocomplete="email"></label>
					<label class="feld"><span class="meta">Aktuelles Passwort (zur Bestätigung)</span><input id="pf-mail-pass" name="password" type="password" required autocomplete="current-password"></label>
					<p class="klein">An die alte Adresse geht zur Sicherheit eine kurze Info.</p>
					<button class="btn" type="submit">E-Mail ändern</button>
				</form>

				<form class="card profil__card" id="pf-pass">
					<span class="meta">Passwort ändern</span>
					<input type="text" id="pf-login" autocomplete="username" hidden readonly>
					<label class="feld"><span class="meta">Aktuelles Passwort</span><input id="pf-cur" type="password" required autocomplete="current-password"></label>
					<label class="feld"><span class="meta">Neues Passwort (mind. 10 Zeichen)</span><input id="pf-new" type="password" minlength="10" required autocomplete="new-password"></label>
					<label class="feld"><span class="meta">Neues Passwort wiederholen</span><input id="pf-new2" type="password" minlength="10" required autocomplete="new-password"></label>
					<button class="btn" type="submit">Passwort ändern</button>
				</form>

				<form class="card profil__card" id="pf-notify">
					<span class="meta">Benachrichtigungen</span>
					<label class="check"><input type="checkbox" id="pf-n-versions"><span>Mail, wenn jemand eine neue Version hochlädt</span></label>
					<p class="klein">Die Mail kommt mit Link direkt zum Song. Deine eigenen Uploads lösen keine Mail an dich aus.</p>
				</form>

				<article class="card profil__card">
					<span class="meta">Die Band</span>
					<ul class="band-list" id="pf-band"></ul>
					<p class="klein" id="pf-meta"></p>
				</article>
			</div>
		</section>

		<!-- ===== BANDKASSE ===== -->
		<section class="view kasse" data-view="kasse" hidden>
			<header class="view__head view__head--row">
				<div><span class="meta">04 — Geld</span><h1 class="display-l">Band&shy;kasse</h1></div>
				<div class="actions">
					<button class="btn btn--klein btn--patina" id="k-new-out">+ Ausgabe</button>
					<button class="btn btn--klein" id="k-new-in">+ Einnahme</button>
				</div>
			</header>

			<div class="k-top">
				<p class="k-satz" id="k-satz"></p>
				<p class="k-kasse meta" id="k-kasse"></p>
			</div>

			<form class="panel k-form" id="k-form" hidden>
				<p class="k-form__titel label" id="k-form-titel">Ausgabe eintragen</p>
				<label class="feld"><span class="meta">Betrag (€)</span><input id="k-amount" inputmode="decimal" placeholder="0,00" required autocomplete="off"></label>
				<label class="feld"><span class="meta">Wofür?</span><input id="k-title" maxlength="190" required placeholder="z. B. Mixing Mikrofilet"></label>
				<label class="feld"><span class="meta" id="k-from-label">Wer hat bezahlt?</span><select id="k-from"></select></label>
				<details class="k-mehr" id="k-mehr">
					<summary class="meta">Mehr Optionen ▾</summary>
					<fieldset class="feld" id="k-split-box"><legend class="meta">Aufteilen auf · nichts angehakt = alle</legend><div class="who-pick" id="k-split"></div></fieldset>
					<label class="feld"><span class="meta">Datum</span><input type="date" id="k-date"></label>
					<label class="feld"><span class="meta">Kategorie</span><select id="k-cat"></select></label>
					<label class="feld"><span class="meta">Beleg (Foto oder PDF)</span><input type="file" id="k-receipt" accept="image/*,.pdf,.heic"></label>
					<label class="feld"><span class="meta">Notiz</span><input id="k-note" maxlength="300"></label>
				</details>
				<p class="k-hint" id="k-hint"></p>
				<div class="actions">
					<button class="btn btn--patina" type="submit" id="k-save">Speichern</button>
					<button class="btn" type="button" id="k-cancel">Abbrechen</button>
					<button class="link meta k-del" type="button" id="k-delete" hidden>Löschen</button>
				</div>
			</form>

			<div class="k-confirm" id="k-confirm" hidden></div>

			<h2 class="meta k-h">Deine offenen Beträge</h2>
			<ul class="k-rows" id="k-mine"></ul>
			<p class="klein k-tipp" id="k-tipp" hidden></p>

			<details class="k-more" id="k-recv-box" hidden>
				<summary class="meta">Erhaltene Zahlungen <span id="k-recv-n"></span> ▾</summary>
				<ul class="k-verlauf" id="k-recv"></ul>
			</details>
			<details class="k-more">
				<summary class="meta">Offene Beträge der anderen <span id="k-others-n"></span> ▾</summary>
				<ul class="k-rows" id="k-others"></ul>
			</details>
			<details class="k-more">
				<summary class="meta">Verlauf · alle Einträge ▾</summary>
				<ul class="k-verlauf" id="k-list"></ul>
				<a class="link meta" href="<?php echo esc_url( add_query_arg( 'kasse_csv', 1, glp_portal_url() ) ); ?>">Als Tabelle exportieren (CSV) ↓</a>
			</details>
		</section>

		<!-- ===== PRESSEPOOL ===== -->
		<section class="view" data-view="pool" hidden>
			<header class="view__head"><span class="meta">05 — In Planung</span><h1 class="display-l">Presse&shy;pool</h1></header>
			<p class="coming meta meta--hell">Kommt als nächste Ausbaustufe: Upload, Kategorien nach Ära, ZIP-Download, Haken „öffentlich“ → erscheint automatisch im Pressekit der Website.</p>
		</section>

		<!-- ===== PR ===== -->
		<section class="view" data-view="pr" hidden>
			<header class="view__head"><span class="meta">06 — In Planung</span><h1 class="display-l">PR-Zen&shy;trale</h1></header>
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
		<section class="versions" id="d-versions">
			<div class="versions__head"><span class="meta">Versionen</span><span class="meta" id="d-max"></span></div>
			<div class="bpm-hint" id="d-bpmhint" hidden></div>
			<ol class="versions__list" id="d-vlist"></ol>
			<form class="versions__up" id="d-upload">
				<label class="versions__drop" id="d-drop">
					<input type="file" id="d-file" accept=".mp3,.m4a,.aac,.wav,audio/*" hidden>
					<span class="label">＋ Neue Version hochladen</span>
					<span class="meta">MP3 hierher ziehen oder antippen</span>
				</label>
				<input id="d-vnote" maxlength="120" placeholder="Notiz, z. B. Rough Mix, neue Vocals" aria-label="Notiz zur Version">
				<div class="bar" id="d-upbar" hidden><i></i></div>
			</form>
		</section>
		<section class="versions files" id="d-files">
			<div class="versions__head"><span class="meta">Tabs, Noten &amp; Dateien</span><span class="meta">Guitar Pro · MuseScore · MIDI · PDF</span></div>
			<ol class="versions__list" id="d-flist"></ol>
			<label class="versions__drop versions__drop--klein" id="d-fdrop">
				<input type="file" id="d-ffile" accept=".gp,.gp3,.gp4,.gp5,.gpx,.mscz,.mid,.midi,.pdf,.txt,.png,.jpg,.jpeg" hidden>
				<span class="label">＋ Datei hinzufügen</span>
			</label>
			<div class="bar" id="d-fupbar" hidden><i></i></div>
		</section>
		<section class="release" id="d-release" hidden>
			<div class="versions__head"><span class="meta">Release-Checkliste</span><b class="meta" id="d-rel-n"></b></div>
			<ul class="release__list" id="d-rel-list"></ul>
		</section>
		<ol class="steps" id="d-steps"></ol>
		<label class="feld"><span class="meta">Notizen / Was fehlt noch?</span><textarea data-meta="note" id="d-m-note" rows="3"></textarea></label>
		<label class="feld"><span class="meta">Lyrics</span><textarea data-meta="lyrics" id="d-m-lyrics" rows="8" class="lyrics" placeholder="Strophe 1 …"></textarea></label>
		<details class="history history--song" id="d-history">
			<summary class="history__sum"><span class="meta">Verlauf</span><span class="label">Änderungen an diesem Song</span><span class="history__chev" aria-hidden="true">▾</span></summary>
			<ol class="history__list" id="d-hlist"></ol>
		</details>
		<div class="drawer__foot"><span class="meta" id="d-saved"></span><button class="btn btn--klein btn--gefahr" id="d-delete">Song löschen</button></div>
	</aside>
</div>

<div class="player" id="player" hidden>
	<button class="player__btn" id="pl-toggle" aria-label="Abspielen/Pause">▶</button>
	<div class="player__info">
		<span class="label" id="pl-title"></span>
		<span class="meta" id="pl-sub"></span>
		<input type="range" id="pl-seek" min="0" max="1000" value="0" aria-label="Position">
	</div>
	<span class="meta player__time" id="pl-time">0:00</span>
	<button class="player__x" id="pl-close" aria-label="Player schließen">✕</button>
	<audio id="pl-audio" preload="metadata"></audio>
</div>

<div class="toast meta meta--hell" id="toast" role="status"></div>

<script>window.GLP = <?php echo wp_json_encode( array( 'rest' => esc_url_raw( rest_url( GLP_NS . '/' ) ), 'nonce' => wp_create_nonce( 'wp_rest' ) ) ); ?>;</script>
<script src="<?php echo esc_url( GLP_URL . 'assets/js/portal.js?ver=' . $v( 'assets/js/portal.js' ) ); ?>"></script>
<?php endif; ?>
</body>
</html>

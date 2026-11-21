{**
 * Shared <head> and styles for the certificate dashboard pages (standalone, no OJS layout).
 *}
<!DOCTYPE html>
<html lang="{$currentLocale|replace:'_':'-'|escape}">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<title>{$dashboardTitle|escape} – {$journalName|escape}</title>
{literal}
	<style>
		:root { --navy:#002A59; --blue:#033187; --green:#4EB902; --magenta:#80076C; --grey:#5A6472; --line:#dde3ec; --bg:#f3f6fa; --red:#b3261e; --amber:#a15c00; }
		* { box-sizing: border-box; }
		body { margin:0; background:var(--bg); color:#1d2733; font:15px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,Helvetica,Arial,sans-serif; }
		a { color:var(--blue); }
		.bj-top { background:#fff; border-bottom:3px solid var(--green); }
		.bj-top-in { max-width:76rem; margin:0 auto; padding:.7rem 1rem; display:flex; align-items:center; gap:.9rem; flex-wrap:wrap; }
		.bj-top img { width:44px; height:44px; object-fit:contain; }
		.bj-kicker { font-size:.78rem; color:var(--grey); letter-spacing:.02em; }
		.bj-brand { margin:0; font-size:1.25rem; color:var(--navy); line-height:1.2; }
		.bj-nav { margin-left:auto; display:flex; gap:.4rem; flex-wrap:wrap; }
		.bj-nav a { text-decoration:none; padding:.4rem .75rem; border:1px solid var(--line); border-radius:6px; color:var(--blue); font-size:.9rem; background:#fff; }
		.bj-nav a:hover { border-color:var(--blue); }
		.bj-wrap { max-width:76rem; margin:1.25rem auto 3rem; padding:0 1rem; }
		.bj-card { background:#fff; border:1px solid var(--line); border-radius:10px; padding:1.1rem 1.25rem; margin-bottom:1rem; }
		.bj-card h2 { margin:0 0 .75rem; font-size:1.02rem; color:var(--navy); }
		.bj-flash, .bj-banner { border-left:5px solid var(--green); background:#f1fae8; padding:.75rem 1rem; border-radius:6px; margin-bottom:1rem; }
		.bj-flash.is-error, .bj-banner.is-error { border-left-color:var(--red); background:#fdeceb; }
		.bj-flash.is-warn, .bj-banner.is-warn { border-left-color:var(--amber); background:#fff6e5; }
		.bj-stats { display:grid; grid-template-columns:repeat(auto-fit,minmax(9.5rem,1fr)); gap:.7rem; margin-bottom:1rem; }
		.bj-stat { display:block; text-decoration:none; background:#fff; border:1px solid var(--line); border-radius:10px; padding:.8rem 1rem; color:inherit; }
		.bj-stat:hover { border-color:var(--blue); }
		.bj-stat b { display:block; font-size:1.7rem; line-height:1.1; color:var(--navy); }
		.bj-stat span { font-size:.82rem; color:var(--grey); }
		.bj-stat.is-hot { border-color:var(--magenta); } .bj-stat.is-hot b { color:var(--magenta); }
		.bj-stat.is-bad b { color:var(--red); }
		.bj-filter { display:flex; gap:.6rem; flex-wrap:wrap; align-items:flex-end; }
		.bj-filter label { display:block; font-size:.78rem; color:var(--grey); margin-bottom:.15rem; }
		.bj-filter input, .bj-filter select { font:inherit; padding:.45rem .6rem; border:1px solid #b9c3d1; border-radius:6px; background:#fff; min-width:9rem; }
		.bj-filter .grow { flex:1 1 16rem; } .bj-filter .grow input { width:100%; }
		.btn { font:inherit; display:inline-block; padding:.45rem .9rem; border-radius:6px; border:1px solid var(--blue); background:var(--blue); color:#fff; cursor:pointer; text-decoration:none; }
		.btn:hover { background:var(--navy); border-color:var(--navy); }
		.btn.secondary { background:#fff; color:var(--blue); }
		.btn.secondary:hover { background:#eef3fb; }
		.btn.small { padding:.25rem .6rem; font-size:.85rem; }
		.btn[disabled] { opacity:.45; cursor:not-allowed; }
		.bj-bulk { display:flex; gap:.75rem; flex-wrap:wrap; align-items:center; }
		.bj-bulk form { margin:0; }
		.bj-tablewrap { overflow-x:auto; position:relative; }
		.bj-cols > *, .bj-card { min-width:0; }
		table.bj { width:100%; border-collapse:collapse; font-size:.9rem; }
		table.bj th { text-align:left; font-weight:600; color:var(--grey); font-size:.78rem; text-transform:uppercase; letter-spacing:.03em; padding:.5rem .6rem; border-bottom:2px solid var(--line); white-space:nowrap; }
		table.bj td { padding:.65rem .6rem; border-bottom:1px solid var(--line); vertical-align:top; }
		table.bj tr:hover td { background:#fafcff; }
		.bj-title { font-weight:600; color:var(--navy); text-decoration:none; } .bj-title:hover { text-decoration:underline; }
		.bj-sub { color:var(--grey); font-size:.82rem; overflow-wrap:anywhere; }
		.bj-num { font-family:ui-monospace,Menlo,Consolas,monospace; font-size:.82rem; white-space:nowrap; }
		.pill { display:inline-block; padding:.1rem .55rem; border-radius:999px; font-size:.78rem; font-weight:600; white-space:nowrap; }
		.pill.ok { background:#e5f6d6; color:#2c6a00; } .pill.warn { background:#fff0d1; color:var(--amber); }
		.pill.bad { background:#fde1df; color:var(--red); } .pill.off { background:#e9edf2; color:var(--grey); }
		.pill.info { background:#e3ebfa; color:var(--blue); }
		.bj-pager { display:flex; justify-content:space-between; align-items:center; margin-top:.9rem; color:var(--grey); font-size:.9rem; }
		.bj-empty { text-align:center; padding:2.2rem 1rem; color:var(--grey); }
		.bj-cols { display:grid; grid-template-columns:minmax(0,3fr) minmax(0,2fr); gap:1rem; align-items:start; }
		@media (max-width:56rem) { .bj-cols { grid-template-columns:minmax(0,1fr); } }
		dl.bj-dl { display:grid; grid-template-columns:9.5rem 1fr; gap:.4rem .9rem; margin:0; }
		dl.bj-dl dt { color:var(--grey); } dl.bj-dl dd { min-width:0; margin:0; overflow-wrap:anywhere; color:var(--navy); font-weight:500; }
		@media (max-width:36rem) { dl.bj-dl { grid-template-columns:minmax(0,1fr); } dl.bj-dl dt { margin-top:.4rem; } }
		.bj-next { border-left:5px solid var(--blue); background:#eef3fb; padding:.75rem 1rem; border-radius:6px; margin:.8rem 0 0; }
		.bj-next.is-warn { border-left-color:var(--amber); background:#fff6e5; }
		.bj-next.is-bad { border-left-color:var(--red); background:#fdeceb; }
		.bj-next.is-ok { border-left-color:var(--green); background:#f1fae8; }
		.bj-act { padding:.8rem 0; border-top:1px solid var(--line); } .bj-act:first-of-type { border-top:0; padding-top:0; }
		.bj-act p { margin:.3rem 0 0; color:var(--grey); font-size:.85rem; }
		.bj-act form { margin:0; }
		.bj-links a { display:block; margin:.25rem 0; }
		.bj-mono { font-family:ui-monospace,Menlo,Consolas,monospace; font-size:.8rem; }
		.bj-err { color:var(--red); }
		code.bj-copy { display:block; background:#f3f6fa; border:1px solid var(--line); border-radius:6px; padding:.4rem .6rem; font-size:.78rem; overflow-wrap:anywhere; }
		.sr-only { position:absolute; width:1px; height:1px; overflow:hidden; clip:rect(0 0 0 0); }
	</style>
{/literal}
</head>
<body>

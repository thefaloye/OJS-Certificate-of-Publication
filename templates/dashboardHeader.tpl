{**
 * Shared page header for the certificate dashboard pages.
 *}
<header class="bj-top">
	<div class="bj-top-in">
		{if $logoDataUri}<img src="{$logoDataUri}" alt="" />{/if}
		<div>
			<div class="bj-kicker">{$journalName|escape}</div>
			<h1 class="bj-brand">{translate key="plugins.generic.bijphgrPublicationCertificate.dash.title"}</h1>
		</div>
		<nav class="bj-nav" aria-label="{translate key="plugins.generic.bijphgrPublicationCertificate.dash.navLabel"}">
			<a href="{$indexUrl|escape}">{translate key="plugins.generic.bijphgrPublicationCertificate.dash.navList"}</a>
			{if $canManage}<a href="{$settingsUrl|escape}">{translate key="plugins.generic.bijphgrPublicationCertificate.dash.navSettings"}</a>{/if}
			<a href="{$ojsDashboardUrl|escape}">{translate key="plugins.generic.bijphgrPublicationCertificate.dash.navOjs"}</a>
		</nav>
	</div>
</header>

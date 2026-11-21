{**
 * Certificate dashboard: summary, filters and the list of certificates.
 *}
{assign var=k value="plugins.generic.bijphgrPublicationCertificate.dash."}
{include file=$dashHead}
{include file=$dashHeader}

<main class="bj-wrap">

	{if $flash}
		<div class="bj-flash is-{$flash.type|escape}" role="status">{$flash.text|escape}</div>
	{/if}

	{if $configMissing}
		<div class="bj-banner is-warn" role="alert">
			<strong>{translate key="plugins.generic.bijphgrPublicationCertificate.settings.incomplete"}</strong>
			{translate key="plugins.generic.bijphgrPublicationCertificate.settings.incompleteHelp"}
			{if $canManage}<a href="{$settingsUrl|escape}">{translate key="`$k`openSettings"}</a>{/if}
		</div>
	{/if}

	<section class="bj-stats" aria-label="{translate key="`$k`summary"}">
		<a class="bj-stat" href="{$indexUrl|escape}"><b>{$counts.total|escape}</b><span>{translate key="`$k`stat.total"}</span></a>
		<a class="bj-stat" href="{$sentUrl|escape}"><b>{$counts.sent|escape}</b><span>{translate key="`$k`stat.sent"}</span></a>
		<a class="bj-stat{if $counts.attention > 0} is-hot{/if}" href="{$attentionUrl|escape}"><b>{$counts.attention|escape}</b><span>{translate key="`$k`stat.attention"}</span></a>
		<a class="bj-stat{if $counts.failed > 0} is-bad{/if}" href="{$failedUrl|escape}"><b>{$counts.failed|escape}</b><span>{translate key="`$k`stat.failed"}</span></a>
		<a class="bj-stat" href="{$waitingUrl|escape}"><b>{$counts.waiting|escape}</b><span>{translate key="`$k`stat.waiting"}</span></a>
		<a class="bj-stat" href="{$withdrawnUrl|escape}"><b>{$counts.withdrawn|escape}</b><span>{translate key="`$k`stat.withdrawn"}</span></a>
	</section>

	{if $canManage && ($counts.failed > 0 || $counts.held > 0) && !$configMissing}
		<section class="bj-card bj-bulk">
			{if $counts.failed > 0}
				<form method="post" action="{$retryUrl|escape}" data-confirm="{translate key="`$k`confirm.retryFailed" n=$counts.failed}" onsubmit="return confirm(this.getAttribute('data-confirm'));">
					{csrf}
					<button class="btn" type="submit">{translate key="`$k`bulk.retryFailed" n=$counts.failed}</button>
				</form>
			{/if}
			{if $counts.held > 0}
				<form method="post" action="{$releaseUrl|escape}">
					{csrf}
					<button class="btn secondary" type="submit">{translate key="`$k`bulk.releaseHeld" n=$counts.held}</button>
				</form>
			{/if}
		</section>
	{/if}

	<section class="bj-card">
		<form class="bj-filter" method="get" action="{$indexUrl|escape}">
			<div class="grow">
				<label for="bj-q">{translate key="`$k`filter.search"}</label>
				<input id="bj-q" type="search" name="q" value="{$filters.q|escape}" maxlength="100" placeholder="{translate key="`$k`filter.searchPlaceholder"}">
			</div>
			<div>
				<label for="bj-status">{translate key="`$k`filter.status"}</label>
				<select id="bj-status" name="status">
					<option value="">{translate key="`$k`filter.any"}</option>
					<option value="valid"{if $filters.status == 'valid'} selected{/if}>{translate key="`$k`status.valid"}</option>
					<option value="needs_revision"{if $filters.status == 'needs_revision'} selected{/if}>{translate key="`$k`status.needs_revision"}</option>
					<option value="withdrawn"{if $filters.status == 'withdrawn'} selected{/if}>{translate key="`$k`status.withdrawn"}</option>
				</select>
			</div>
			<div>
				<label for="bj-email">{translate key="`$k`filter.email"}</label>
				<select id="bj-email" name="email">
					<option value="">{translate key="`$k`filter.any"}</option>
					<option value="sent"{if $filters.email == 'sent'} selected{/if}>{translate key="`$k`email.sent"}</option>
					<option value="failed"{if $filters.email == 'failed'} selected{/if}>{translate key="`$k`email.failed"}</option>
					<option value="pending"{if $filters.email == 'pending'} selected{/if}>{translate key="`$k`email.pending"}</option>
					<option value="queued"{if $filters.email == 'queued'} selected{/if}>{translate key="`$k`email.queued"}</option>
				</select>
			</div>
			<div>
				<label for="bj-att">&nbsp;</label>
				<label class="bj-check" style="display:flex;gap:.35rem;align-items:center;margin:0;padding:.5rem 0;color:#1d2733;font-size:.9rem">
					<input id="bj-att" type="checkbox" name="attention" value="1" style="min-width:0"{if $filters.attention} checked{/if}> {translate key="`$k`filter.attention"}
				</label>
			</div>
			<div>
				<button class="btn" type="submit">{translate key="`$k`filter.apply"}</button>
				<a class="btn secondary" href="{$resetUrl|escape}">{translate key="`$k`filter.reset"}</a>
			</div>
		</form>
	</section>

	<section class="bj-card">
		{if $rows|@count == 0}
			<div class="bj-empty">
				{if $total == 0 && $counts.total == 0}
					<strong>{translate key="`$k`empty.none"}</strong><br>{translate key="`$k`empty.noneHelp"}
				{else}
					{translate key="`$k`empty.noMatch"}
				{/if}
			</div>
		{else}
			<div class="bj-sub" style="margin-bottom:.6rem">{translate key="`$k`resultCount" n=$total}</div>
			<div class="bj-tablewrap">
				<table class="bj">
					<thead>
						<tr>
							<th>{translate key="`$k`col.certificate"}</th>
							<th>{translate key="`$k`col.article"}</th>
							<th>{translate key="`$k`col.recipient"}</th>
							<th>{translate key="`$k`col.published"}</th>
							<th>{translate key="`$k`col.status"}</th>
							<th>{translate key="`$k`col.email"}</th>
							<th><span class="sr-only">{translate key="`$k`col.actions"}</span></th>
						</tr>
					</thead>
					<tbody>
					{foreach from=$rows item=r}
						<tr>
							<td><span class="bj-num">{$r.number|escape}</span>{if $r.version > 1}<div class="bj-sub">{translate key="`$k`versionShort" v=$r.version}</div>{/if}</td>
							<td>
								<a class="bj-title" href="{$r.viewUrl|escape}">{if $r.title}{$r.title|escape}{else}{translate key="`$k`articleGone"}{/if}</a>
								{if $r.authors}<div class="bj-sub">{$r.authors|escape}</div>{/if}
								<div class="bj-sub">{translate key="`$k`submissionId" id=$r.submissionId}{if $r.issueLabel} · {$r.issueLabel|escape}{/if}</div>
							</td>
							<td>
								{if $r.recipientName}{$r.recipientName|escape}{/if}
								{if $r.hasRecipient}<div class="bj-sub">{$r.recipientEmail|escape}</div>
								{elseif $r.articleAvailable}<div class="bj-sub bj-err">{translate key="`$k`noRecipientShort"}</div>{/if}
							</td>
							<td>{$r.publicationDate|escape}</td>
							<td>
								{if $r.held}<span class="pill warn">{translate key="`$k`status.held"}</span>
								{else}<span class="pill {if $r.status == 'valid'}ok{elseif $r.status == 'withdrawn'}off{else}warn{/if}">{translate key="`$k`status.`$r.status`"}</span>{/if}
							</td>
							<td>
								{if $r.status == 'withdrawn'}<span class="pill off">&ndash;</span>
								{else}<span class="pill {if $r.emailStatus == 'sent'}ok{elseif $r.emailStatus == 'failed'}bad{else}warn{/if}">{translate key="`$k`email.`$r.emailStatus`"}</span>
								{if $r.sentAt && $r.emailStatus == 'sent'}<div class="bj-sub">{$r.sentAt|escape}</div>{/if}{/if}
							</td>
							<td style="white-space:nowrap">
								<a class="btn small secondary" href="{$r.viewUrl|escape}">{translate key="`$k`open"}</a>
								{if !$r.held}<a class="btn small secondary" href="{$r.pdfUrl|escape}">PDF</a>{/if}
							</td>
						</tr>
					{/foreach}
					</tbody>
				</table>
			</div>

			{if $pages > 1}
				<nav class="bj-pager" aria-label="{translate key="`$k`pagination"}">
					<span>{if $prevUrl}<a href="{$prevUrl|escape}">&larr; {translate key="`$k`prev"}</a>{/if}</span>
					<span>{translate key="`$k`pageOf" page=$page pages=$pages}</span>
					<span>{if $nextUrl}<a href="{$nextUrl|escape}">{translate key="`$k`next"} &rarr;</a>{/if}</span>
				</nav>
			{/if}
		{/if}
	</section>
</main>
</body>
</html>

{**
 * Public certificate verification page (opened from the QR code)
 *}
{include file="frontend/components/header.tpl" pageTitleTranslated=$pageTitle}

<style>
	.bijphgr-verify { max-width: 46rem; margin: 2rem auto; padding: 0 1rem; }
	.bijphgr-verify h1 { color: #033187; margin-bottom: 1rem; }
	.bijphgr-verify .bijphgr-status { border-left: 6px solid #4EB902; background: #f3faec; padding: .9rem 1.1rem; margin-bottom: 1.5rem; }
	.bijphgr-verify .bijphgr-status.is-warn { border-left-color: #80076C; background: #faf0f8; }
	.bijphgr-verify dl { display: grid; grid-template-columns: 11rem 1fr; gap: .5rem 1rem; margin: 0; }
	.bijphgr-verify dt { color: #5A6472; font-weight: normal; }
	.bijphgr-verify dd { margin: 0; color: #002A59; font-weight: 600; overflow-wrap: anywhere; }
	@media (max-width: 36rem) { .bijphgr-verify dl { grid-template-columns: 1fr; } }
</style>

<div class="page page_bijphgr_verify">
	<div class="bijphgr-verify">
		<h1>{translate key="plugins.generic.bijphgrPublicationCertificate.verify.title"}</h1>

		{if !$found}
			<div class="bijphgr-status is-warn" role="alert">
				{translate key="plugins.generic.bijphgrPublicationCertificate.verify.notFound"}
			</div>
		{else}
			{if $published}
				<div class="bijphgr-status" role="status">
					<strong>{translate key="plugins.generic.bijphgrPublicationCertificate.verify.valid"}</strong>
				</div>
			{else}
				<div class="bijphgr-status is-warn" role="status">
					<strong>{translate key="plugins.generic.bijphgrPublicationCertificate.verify.unavailable"}</strong>
				</div>
			{/if}

			{if $correctionPending}
				<p>{translate key="plugins.generic.bijphgrPublicationCertificate.verify.correctionPending"}</p>
			{/if}

			<dl>
				<dt>{translate key="plugins.generic.bijphgrPublicationCertificate.verify.certificateNumber"}</dt>
				<dd>{$certificateNumber|escape}</dd>
				<dt>{translate key="plugins.generic.bijphgrPublicationCertificate.verify.journal"}</dt>
				<dd>{$journalName|escape}</dd>
				{if $published}
					<dt>{translate key="plugins.generic.bijphgrPublicationCertificate.verify.article"}</dt>
					<dd>{$articleTitle|escape}</dd>
					<dt>{translate key="plugins.generic.bijphgrPublicationCertificate.verify.authors"}</dt>
					<dd>{$authors|escape}</dd>
					{if $issueLabel}
						<dt>{translate key="plugins.generic.bijphgrPublicationCertificate.verify.issue"}</dt>
						<dd>{$issueLabel|escape}</dd>
					{/if}
					<dt>{translate key="plugins.generic.bijphgrPublicationCertificate.verify.publicationDate"}</dt>
					<dd>{$publicationDate|escape}</dd>
					{if $doi}
						<dt>DOI</dt>
						<dd><a href="{$doiUrl|escape}">{$doiUrl|escape}</a></dd>
					{/if}
					<dt>{translate key="plugins.generic.bijphgrPublicationCertificate.verify.articleLink"}</dt>
					<dd><a href="{$articleUrl|escape}">{$articleUrl|escape}</a></dd>
				{/if}
				<dt>{translate key="plugins.generic.bijphgrPublicationCertificate.verify.issued"}</dt>
				<dd>{$issuedDate|escape}</dd>
				{if $certificateVersion > 1}
					<dt>{translate key="plugins.generic.bijphgrPublicationCertificate.verify.revisions"}</dt>
					<dd>
						{foreach from=$revisions item=revision}
							{translate key="plugins.generic.bijphgrPublicationCertificate.verify.revisionLine" version=$revision.version date=$revision.date}<br />
						{/foreach}
					</dd>
				{/if}
			</dl>
		{/if}
	</div>
</div>

{include file="frontend/components/footer.tpl"}

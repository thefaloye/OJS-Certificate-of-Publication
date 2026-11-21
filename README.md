# BIJPHGR Publication Certificate and Notification Plugin

**Version:** 1.2.0  
**Platform:** Open Journal Systems (OJS) 3.5  
**Tested on:** OJS 3.5.0-5  
**PHP:** 8.2+  
**Plugin type:** Generic Plugin

The BIJPHGR Publication Certificate and Notification Plugin automates the issuance of branded PDF Certificates of Publication and sends a publication notification email with a secure certificate-download link to the corresponding author when an article becomes publicly available.

It was developed for the *Bloomfield International Journal of Public Health, Population and Geospatial Research (BIJPHGR)*.

## Features

- Automatic certificate issuance when an article becomes publicly available
- Branded PDF certificates with article and author metadata
- Certificate listing all article authors
- Editor-in-Chief signatory (configured by the journal)
- Optional DOI display when a DOI is available
- Unique certificate number, sequential by journal and publication year
- QR code linking to a public certificate-verification page
- Secure certificate download using a separate tokenised link
- Automatic email notification to the corresponding author
- Customisable email subject and body, with template variables
- Certificate dashboard with search, filters, status tiles, and pagination
- Certificate detail view with version history and activity log
- Manual email resend and certificate regeneration (permissions restricted)
- Bulk retry for failed emails and release of held certificates
- Duplicate protection for repeated events, retries, and concurrent processing
- Automatic certificate revision when published metadata changes (configurable)
- Private certificate storage in OJS `files_dir`
- Audit trail for certificate and administrative events
- Withdrawn-publication handling when an article is unpublished in OJS

## Publication workflow

1. OJS reports a publication event.
2. The plugin checks the publication's actual eligibility/public availability; merely scheduling an article does not issue a certificate.
3. A certificate record is created once, and a background job is queued.
4. The PDF is generated and stored privately.
5. A notification email is sent to the corresponding author with publication information and a secure certificate download link.
6. The certificate and activity record are available in the plugin dashboard.

The plugin processes publication work through OJS's queue. The queue runner must be configured and operating for jobs to complete.

## Installation

### Option A — Upload through OJS

1. Back up the OJS database and `files_dir`.
2. In OJS, open **Website → Plugins → Upload a New Plugin**.
3. Upload the plugin release archive (`.tar.gz`).
4. After installation, go to **Website → Plugins → Generic Plugins** and enable **BIJPHGR Publication Certificate**.
5. Open the plugin settings and complete the required configuration.

### Option B — Install files manually

1. Back up the OJS database and `files_dir`.
2. Copy the `bijphgrPublicationCertificate` directory into:
   `plugins/generic/`
3. Register/install the plugin version and create its tables using the OJS plugin-version tool from the OJS root:
   ```bash
   php lib/pkp/tools/installPluginVersion.php plugins/generic/bijphgrPublicationCertificate/version.xml
   ```
4. In OJS, open **Website → Plugins → Generic Plugins** and enable **BIJPHGR Publication Certificate**.
5. Configure the plugin as described below.

> Follow the installation procedure appropriate to your OJS deployment and verify the plugin's registration and database migration completed successfully before enabling it on a live journal.

## Configuration

Open the plugin's settings page and configure the following:

- **Publisher name:** Required publisher name displayed on certificates.
- **Signatory name:** Required name of the Editor-in-Chief/signatory.
- **Signatory title:** Defaults to `Editor-in-Chief`.
- **Certificate prefix:** Configure the certificate identifier prefix if desired.
- **Email subject and body:** Customise the publication notification email using the available template variables.
- **Download-link validity:** Configure the certificate download-link expiry (default is 180 days; the setting is constrained to 1–3650 days).
- **Automatic metadata revisions:** Enable or disable automatic certificate revision when article metadata changes after publication (enabled by default).
- **Signature image:** Optional PNG or JPEG signature image, maximum 1 MB; image dimensions are limited by the settings form. A transparent PNG is recommended.

The plugin remains enabled but holds processing when the required configuration is incomplete. Once the required settings are completed and saved, held certificates can be released from the plugin workflow.

## Certificate numbering

Certificate numbers use the configured prefix and a sequential counter per journal and publication year. The default format is:


`BIJPHGR-CERT-2026-000001`

The number is allocated transactionally. Certificate numbers are identifiers, not access secrets. Verification and download links use separate tokens.

## Certificate dashboard

The dashboard can be accessed from the OJS sidebar (**Publication Certificates**), the plugin area under **Website → Plugins → Certificates**, or the journal route:

`/{journal}/bijphgrCertificates`

The dashboard provides:

- Summary counts for total, sent, attention-needed, failed, waiting, and withdrawn records
- Search by certificate number, article title, author, recipient email, or article ID
- Filters and paginated results (20 records per page)
- Certificate detail pages with article details, current state, PDF versions, and activity history
- PDF download for authorised editorial users
- Resend publication email (managers and administrators)
- Regenerate certificate (managers and administrators; creates a new PDF revision but does not send email)
- Retry failed emails
- Release certificates held due to incomplete configuration

State-changing dashboard actions use POST requests with CSRF protection and are audit-logged with the acting user.

## Public verification and secure download

The QR code printed on the certificate opens the public verification page. The email download link uses a separate tokenised endpoint.

- Verification token: 160-bit token
- Download token: 256-bit token; only its hash is stored
- Certificate PDF files are stored under `files_dir/bijphgr-certificates/` and are not directly web-accessible
- Download links expire according to the configured validity period
- Download is blocked when the article is no longer publicly published
- Verification hides publication details when the article is withdrawn/unpublished

Public verification and download endpoints are handled by the plugin. The download token is separate from the certificate number; the certificate number is not intended to grant file access.

## Email template variables

Use these variables in the configurable email subject/body:

| Variable | Value |
|---|---|
| `{$authorName}` | Corresponding author name |
| `{$articleTitle}` | Article title |
| `{$articleId}` | OJS submission/article identifier used by the plugin |
| `{$allAuthors}` | All author names |
| `{$journalName}` | Journal title |
| `{$journalUrl}` | Journal homepage URL |
| `{$volume}` | Volume value, when available |
| `{$issue}` | Issue value, when available |
| `{$publicationDate}` | Publication date |
| `{$doi}` | DOI, when available |
| `{$articleUrl}` | Public article URL |
| `{$certificateNumber}` | Certificate identifier |
| `{$certificateUrl}` | Secure certificate download URL |
| `{$issueLine}` | Optional issue details line (omitted when unavailable) |
| `{$doiLine}` | Optional DOI line (omitted when unavailable) |

Template values are HTML-escaped. Optional DOI and issue lines are designed to disappear when the relevant metadata is unavailable.

## Background queue and server configuration

The plugin relies on OJS background jobs to generate PDFs and send notification emails.

Configure and monitor one of the OJS queue-runner options:

- OJS built-in web job runner (`job_runner = On`), or
- A scheduled job runner. The project's README specifies running the OJS job tool every minute:
  ```bash
  php lib/pkp/tools/jobs.php run
  ```

Ensure the command runs from the OJS installation root and under a suitable system user. Confirm the job runner is working before testing a publication.

Also verify:

- `files_dir` is configured outside the web root, as required for OJS file storage.
- `base_url` in `config.inc.php` is set to the correct public journal URL; background jobs use it to construct QR-code verification and email links.
- OJS outgoing email/SMTP is configured and working.
- The journal contact email is set; it is used as the notification sender.
- Backups include the database and `files_dir`.

## Behaviour and edge cases

| Scenario | Plugin behaviour |
|---|---|
| Article scheduled in an unpublished issue | No certificate or email is issued. |
| Article or issue becomes publicly published | Certificate record is created, and background processing is queued. |
| Repeated event, retry, or concurrent worker | Duplicate protection prevents a second certificate and email for the same publication record. |
| No corresponding author designated | Certificate can be generated; email is marked failed with a clear reason. The plugin does not fall back to the first author. Correct the author designation and resend. |
| Mail server temporarily unavailable | Job retry is attempted; certificate is not regenerated solely because email delivery failed. |
| Published metadata changes | The certificate is flagged and, if automatic revision is enabled, a new version is generated and audited. Earlier PDFs are retained. No automatic email is sent for the revision. |
| Article is unpublished | Certificate is marked withdrawn, downloads are blocked, and verification hides publication details. Republishing restores the record without automatically re-sending the email. |

## Data tables

The plugin creates the following tables:

- `bijphgr_certificates`
- `bijphgr_certificate_revisions`
- `bijphgr_certificate_counters`
- `bijphgr_certificate_audit`

Certificate records intentionally do not use foreign keys, so the records can remain after a submission is deleted. The audit table is append-only. Download secrets are not stored in plaintext.

## Known limitations and planned work

- **Back-filling previously published articles:** Certificates for articles published before plugin installation are not automatically created. A reviewed back-fill workflow is planned; it will not automatically send emails to past authors.
- **Formal retraction support:** OJS 3.5 does not provide a dedicated retraction status handled by this plugin. Unpublishing is supported, but formal retraction workflows/statuses are not yet implemented.
- **Later publication versions:** Each newly published version currently receives its own certificate and publication email. A configurable policy to issue certificates for later versions without emailing is a future enhancement.

## Third-party libraries

The plugin bundles:

- `chillerlan/php-qrcode` 5.0.5 — QR code generation, licensed under Apache-2.0 or MIT (see bundled license files and notices).
- `chillerlan/php-settings-container` 3.2.1 — settings container, MIT license (see bundled license files).

PDF rendering uses mPDF already bundled with OJS; it does not add another PDF rendering dependency. The QR code is rendered as SVG, so GD is not required for QR generation.

Review bundled `LICENSE` and `NOTICE` files for the third-party terms.

## Troubleshooting

- **Certificates are held/not issuing:** Confirm the plugin is enabled and required signatory/publisher settings are complete. Check the dashboard for held items and release them after correcting the configuration.
- **Email is not sent:** Confirm the corresponding author is designated in OJS, the author email is correct, journal contact email is configured, SMTP is working, and queue jobs are being processed. Review the dashboard status and OJS/PHP logs.
- **Links or QR code point to the wrong site:** Verify `base_url` in `config.inc.php` is the correct public URL and re-check plugin settings; regenerate the certificate if needed.
- **Jobs remain pending:** Check the OJS job runner/cron configuration and logs.
- **Download expired:** Resend the notification to issue a fresh download link. Resending invalidates the previous download link.

## License

The plugin's own license is not specified in this README. Add a `LICENSE` file and declare the plugin license before public distribution. Third-party components retain their respective licenses.


## Acknowledgements

Developed for the *Bloomfield International Journal of Public Health, Population and Geospatial Research (BIJPHGR)*, published by Bloomfield International Learning and Evaluation Network.

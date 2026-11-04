# Registration documents and placement requests

This guide describes the registration and placement changes prepared on 21 October 2026. It applies after the updated plugin is installed. No production documents or player records are changed merely by installing it.

## Configure the approved documents

Open **LeagueFlow → Settings → Registration Windows → Waiver and Code of Conduct**.

There are separate document settings for **Participation waiver** and **Intramural Code of Conduct**. For each document:

1. Enter the document title and a clear version or effective date, for example `2026-10-21`.
2. Paste the approved document text, add a document URL, or provide both. Basic HTML is supported in the text field.
3. Select **Require personal acknowledgement during registration** when the document is ready to use.
4. Save Settings and check the player and captain registration forms.

No waiver or Code of Conduct wording is supplied by the plugin. The email thread requested those documents, but their contents were not provided. Until a requirement is enabled, registration does not require that document. An enabled document without a version or readable text/URL blocks registration and displays an explanation; it does not silently become optional.

Use a new, versioned URL when replacing a linked PDF. Acceptance records keep the URL and version, but **do not download or preserve the linked PDF bytes**. Pasted document text is preserved exactly as a sanitized snapshot. Changing a title, version, text or URL creates a different document fingerprint and requires a new acknowledgement. Updating a PDF in place at an unchanged URL cannot be detected by the plugin.

## What participants see

Player and captain onboarding show the documents in the final review step. Each required document has its own unchecked acknowledgement. Participants can expand the approved text or open its linked document in a new tab before checking the box.

The same requirements apply when an existing player submits a new team/placement request or saves their player profile. Existing participants see a **Review your registration documents** panel when a required document is added or updated. They can use **Save acknowledgements** without registering again. Captains who normally land on their team workspace see this renewal panel too.

Ordinary onboarding drafts are retained after validation errors. Agreement checkboxes and document revision tokens are never restored from browser draft storage: the participant must make the acknowledgement in the current form. Server validation independently checks each required document and its current revision, so bypassing browser controls does not complete registration.

Captain acceptance applies to the captain's own player identity. It does not accept documents for other team members.

## Check the acceptance record

Open **LeagueFlow → Players**, edit the relevant player, and find **Registration acknowledgements**.

The read-only panel reports whether the player has accepted the current requirements. Each saved evidence entry contains the authenticated account ID, player ID, UTC acceptance time, and the accepted document title, version, URL, text and fingerprint. Renewals append another record; they do not replace the earlier text or date. These records are stored in hidden player metadata and are not registered for the public REST API.

A newly linked account must accept for itself; it does not inherit the old account's acknowledgement. Staff and captains cannot mark another player's documents accepted. The referee desk shows only **Current**, **Needs review** or **Not required**, rather than the document evidence or contact details.

## Existing players, imports and manual roster additions

Installing the update does not manufacture historical acceptance records or automatically remove players from their teams. Existing participants with newly required documents are prompted on their next portal visit.

A pending team or placement request cannot be approved by staff or a captain until its player has acknowledged the current required documents. The Requests inbox explains the outstanding requirement and the captain's approval control is disabled. Declining a request or closing a request that is already satisfied remains possible.

Authorized staff can still add players manually, and existing imports and roster entries remain intact. Such a roster change does **not** count as personal acceptance. Check the player's acknowledgement status and have the player sign into their own portal. The acknowledgement status reports document acceptance; it is not a separate participation eligibility decision.

If a manually entered player has no linked account, link or provision the account using the existing authorized player-account controls. Have that participant complete the acknowledgement in their portal. Do not create a second player record just to record acceptance.

Assigning a player account or team manager requires the dedicated player-account permission together with WordPress user-editing and role-management permissions. New links accept only eligible participant accounts. Ordinary post-editing access does not grant account assignment. Existing unchanged assignments can be retained; removing a manager also requires authority over that account. The same policy applies to registered identity metadata if another integration enables generic REST custom fields.

## Clear already-placed requests

Normal additions through the player editor or team-manager portal already close matching pending requests automatically. Matching means the same player record, the same sport, and the requested level where one was specified. Named team requests must also match the assigned team. Request history is retained.

Older requests can remain pending when the membership existed before automatic reconciliation, came from an import, or was changed through another integration. To clean these up:

1. Open **LeagueFlow → Requests** and choose the relevant sport, level and request type.
2. Expand **Review N already-placed requests on this page**.
3. Review the named players and requests. The preview contains only requests whose exact player record is already on a matching team.
4. Select **Close these already-placed requests**.
5. Review the result notice. Changed, locked or unavailable requests are skipped and can be reviewed again.

The action handles at most the 50 requests displayed on one page. It marks satisfied requests Approved, records their matching team and appends a history entry. It does not delete requests, alter a roster or send notification email. Repeating an old submission does not duplicate the decision.

A request without a matching membership is not included. If the roster contains a separate player record with a similar name, resolve that identity issue manually before closing the original request. Names alone are not used to merge players or infer that a placement is satisfied. Wrong-level and ambiguous memberships continue to require staff review.

Core WordPress REST request records remain readable by authorized staff, but changes must use the LeagueFlow request workflow. Generic REST edits and deletions cannot bypass request state checks, membership reconciliation or history recording.

## Validation included

`tests/wordpress-registration.php` exercises the actual WordPress portal and admin-post handlers in a disposable installation. It covers missing, unchecked, malformed and stale acknowledgements; player and captain success; renewal and identity ownership; failed evidence-write rollback; pending-request approval restrictions; and state-aware placement cleanup. `tests/portal-consent.cjs` confirms that browser draft recovery retains ordinary fields while leaving consent unchecked and keeping the server's current document revision.

`tests/wordpress-rest-identity.php` covers restricted identity writes, permitted ordinary edits, authorized account and manager assignment, privileged-target rejection, staff source reads and metadata round-trips, hidden acceptance evidence, and prevention of request-workflow bypasses. It explicitly enables custom fields only inside the disposable test to verify the registered-meta permissions an integration could expose; default plugin post types do not enable generic REST custom fields.

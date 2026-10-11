-- Deterministic multilingual knowledgebase seed.
-- Existing topics and localized articles always win.
INSERT INTO knowledgebase_topics (
    topic_key, is_pinned, sort_order, created_at, updated_at
)
SELECT
    legacy.slug, legacy.is_pinned, legacy.sort_order, legacy.created_at, legacy.updated_at
FROM knowledgebase_threads AS legacy
WHERE NOT EXISTS (
    SELECT 1
    FROM knowledgebase_topics AS existing_topic
    WHERE existing_topic.topic_key COLLATE utf8mb4_general_ci = legacy.slug COLLATE utf8mb4_general_ci
);

INSERT INTO knowledgebase_articles (
    topic_id, locale, author_user_id, title, excerpt, body_html,
    status, published_at, created_at, updated_at
)
SELECT
    topic.id, 'en', legacy.author_user_id, legacy.title, legacy.excerpt, legacy.body_html,
    legacy.status, legacy.published_at, legacy.created_at, legacy.updated_at
FROM knowledgebase_threads AS legacy
INNER JOIN knowledgebase_topics AS topic
    ON topic.topic_key COLLATE utf8mb4_general_ci = legacy.slug COLLATE utf8mb4_general_ci
WHERE legacy.slug NOT IN ('getting-started-first-forward-checklist', 'connection-modes-single-race-bond', 'bond-sidecar-setup', 'troubleshooting-common-mistakes')
  AND NOT EXISTS (
      SELECT 1
      FROM knowledgebase_articles AS existing_article
      WHERE existing_article.topic_id = topic.id
        AND existing_article.locale = 'en'
  );

INSERT INTO knowledgebase_topics (
    topic_key, is_pinned, sort_order, created_at, updated_at
)
SELECT
    'getting-started-five-minute-overview', 1, 10, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
WHERE NOT EXISTS (
    SELECT 1
    FROM knowledgebase_topics AS existing_topic
    WHERE existing_topic.topic_key = 'getting-started-five-minute-overview'
);

INSERT INTO knowledgebase_topics (
    topic_key, is_pinned, sort_order, created_at, updated_at
)
SELECT
    'install-client', 0, 20, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
WHERE NOT EXISTS (
    SELECT 1
    FROM knowledgebase_topics AS existing_topic
    WHERE existing_topic.topic_key = 'install-client'
);

INSERT INTO knowledgebase_topics (
    topic_key, is_pinned, sort_order, created_at, updated_at
)
SELECT
    'getting-started-first-forward-checklist', 0, 30, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
WHERE NOT EXISTS (
    SELECT 1
    FROM knowledgebase_topics AS existing_topic
    WHERE existing_topic.topic_key = 'getting-started-first-forward-checklist'
);

INSERT INTO knowledgebase_topics (
    topic_key, is_pinned, sort_order, created_at, updated_at
)
SELECT
    'connect-and-verify', 0, 40, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
WHERE NOT EXISTS (
    SELECT 1
    FROM knowledgebase_topics AS existing_topic
    WHERE existing_topic.topic_key = 'connect-and-verify'
);

INSERT INTO knowledgebase_topics (
    topic_key, is_pinned, sort_order, created_at, updated_at
)
SELECT
    'troubleshooting-common-mistakes', 0, 50, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
WHERE NOT EXISTS (
    SELECT 1
    FROM knowledgebase_topics AS existing_topic
    WHERE existing_topic.topic_key = 'troubleshooting-common-mistakes'
);

INSERT INTO knowledgebase_topics (
    topic_key, is_pinned, sort_order, created_at, updated_at
)
SELECT
    'glossary', 0, 60, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
WHERE NOT EXISTS (
    SELECT 1
    FROM knowledgebase_topics AS existing_topic
    WHERE existing_topic.topic_key = 'glossary'
);

INSERT INTO knowledgebase_topics (
    topic_key, is_pinned, sort_order, created_at, updated_at
)
SELECT
    'no-gui-clients', 0, 70, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
WHERE NOT EXISTS (
    SELECT 1
    FROM knowledgebase_topics AS existing_topic
    WHERE existing_topic.topic_key = 'no-gui-clients'
);

INSERT INTO knowledgebase_articles (
    topic_id, locale, author_user_id, title, excerpt, body_html,
    status, published_at, created_at, updated_at
)
SELECT
    topic.id, 'en', NULL, 'Get Connected in Five Minutes', 'Follow the shortest path from an active package to a verified connection.', '<h2>What you will accomplish</h2>
<p>You will go from an active plan to a working line: add one forward in the panel, install the app, connect, and see the line running. About five minutes, and you never have to choose a connection type - every line uses ultra, which keeps the route steady while you play.</p>

<h2>Before you begin</h2>
<ul>
  <li>An active plan, so the panel lets you create a forward.</li>
  <li>The game or program you want to speed up, and the address it connects to (the game''s server list usually shows it).</li>
  <li>Windows, Android, or Linux on the device you play on. A phone works as well as a computer.</li>
  <li>Your panel login, and about five minutes.</li>
</ul>

<h2>Steps</h2>
<pre class="mermaid">flowchart TD
accTitle: From a plan to a working line
accDescr: Create a forward, install the app, connect it, then point the game at the local port.
A[Open the panel] --&gt; B[Create a forward]
B --&gt; C[Install the app]
C --&gt; D[Sign in and connect]
D --&gt; E[Point the game at the local port]
E --&gt; F[Play]</pre>
<p>Five steps, each one covered in a few lines below. Nothing here needs a technical decision.</p>
<figure class="kb-screenshot-figure">
  <img src="/assets/knowledgebase/screenshots/tenant/01-overview-en.png" width="1440" height="960" alt="Panel overview showing active packages, forwards and the three setup steps">
  <figcaption>Your starting point: the Overview page tells you which step comes next.</figcaption>
</figure>
<ol>
  <li><strong>Open the panel and check the plan is active.</strong> Latest package on the Overview page shows its status; if it is expired or suspended, you cannot create a forward.</li>
  <li><strong>Create a forward.</strong> Open <strong>My Services</strong> and click <strong>New forward</strong> on your package. Pick the line closest to the game''s server, type the address and port the game connects to, keep the suggested local port, and save. Note that local port down - the game needs it in step 5.</li>
  <li><strong>Install the app</strong> on the device you play on: Windows, macOS or Android from the Clients page, or the one-line Linux command. Sign in with your panel account.</li>
  <li><strong>Connect.</strong> The app lists your forward; press connect and wait until it reads running.</li>
  <li><strong>Point the game at the client.</strong> Wherever the game asks for a server address, use <code>127.0.0.1</code> and the local port from step 2, then start the game.</li>
  <li><strong>Play, and glance at the panel afterwards.</strong> The forward''s <strong>Usage this cycle</strong> goes up while you play.</li>
</ol>

<h2>How to know it worked</h2>
<p>Three signs, in this order: the app shows the line as running, the forward in the panel shows incoming traffic while you play, and the game itself stays steady instead of spiking in the middle of a match. If the game starts but the traffic numbers stay at zero, the local port is almost always the reason - check step 5 again.</p>

<h2>If it did not work</h2>
<p><strong>Stop and check the plan first</strong> if the panel refuses to save the forward: an expired plan is the most common reason on day one. The next most common is the wrong destination - use the address the game really connects to, not the address of the site you downloaded it from. Third is the local port: the game has to use exactly the port the panel assigned. Everything else - the app will not stay connected, the line keeps dropping, the game cannot reach the server - is covered in the troubleshooting guide below.</p>

<h2>Related guides</h2>
<ul>
  <li><a href="/knowledgebase?thread=getting-started-first-forward-checklist">Create Your First Forward</a></li>
  <li><a href="/knowledgebase?thread=install-client">Install the Client</a></li>
  <li><a href="/knowledgebase?thread=connect-and-verify">Connect and Verify</a></li>
</ul>
',
    'published', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM knowledgebase_topics AS topic
WHERE topic.topic_key = 'getting-started-five-minute-overview'
  AND NOT EXISTS (
      SELECT 1
      FROM knowledgebase_articles AS existing_article
      WHERE existing_article.topic_id = topic.id
        AND existing_article.locale = 'en'
  );

INSERT INTO knowledgebase_articles (
    topic_id, locale, author_user_id, title, excerpt, body_html,
    status, published_at, created_at, updated_at
)
SELECT
    topic.id, 'en', NULL, 'Install the Client', 'Install and sign in on Windows, Android, or Linux, then confirm the client is online.', '<p>Install the app once on the device you play on, sign in, and leave it running. After that you only open it to check that the line is still up.</p>

<h2>What you will accomplish</h2>
<p>The app installed on your device, signed in with your panel account, and listed among the online devices on the Clients page.</p>

<h2>Before you begin</h2>
<ul>
<li>Your panel email and password.</li>
<li>The device you play on. The app runs on Windows, macOS, Android, and Linux.</li>
<li>Android 8.0+ for phones and tablets.</li>
<li>On Linux, Debian 11, 12 or 13 (x86_64 or arm64) and one root command.</li>
</ul>

<h2>Steps</h2>
<ol>
<li><strong>Open the Clients page.</strong> Everything you need sits on that one page.</li>
<li><strong>Install the app.</strong> On Windows use the download button in the <strong>Windows App</strong> box. On Android click <strong>Download APK</strong>. On macOS pick the download for your chip. On Linux copy the command in the <strong>Linux</strong> box and run it on the target machine.</li>
<li><strong>Sign in</strong> with your panel email and password. The app answers <strong>Connection successful!</strong> when it is in.</li>
<li><strong>Leave it running.</strong> The app shows <strong>Connected</strong> and the switch reads <strong>Running</strong>.</li>
</ol>
<pre class="mermaid">
flowchart TD
accTitle: Client install path
accDescr: Pick your device, install the app, sign in, then confirm the client is online
A[Pick your device] --> B[Operating system]
B --> C[Download the Windows installer]
B --> D[Download the Android APK]
B --> E[Copy Linux install command]
C --> F[Sign in]
D --> F
E --> F
F --> G[Client reads Connected and Running]
</pre>
<p>Every device finishes in the same place: the app is signed in and the Clients page lists it as online.</p>
<figure><img src="/assets/knowledgebase/screenshots/tenant/03-client-install-en.png" width="1440" height="960" alt="Clients page showing the Windows download button and the Linux install command"><figcaption>The Clients page holds every download and the Linux install command.</figcaption></figure>

<h2>How to know it worked</h2>
<p>Two checks. The app shows <strong>Connected</strong>, and the online devices list at the bottom of the Clients page shows your device (IP, system and app version).</p>

<h2>If it did not work</h2>
<p><strong>Stop</strong> and sign in again if the app never leaves <strong>Connecting...</strong>. Most likely first: the password is wrong or the account is not active yet. Then: Android put the app to sleep in the background. Then: the Linux command was run without root.</p>

<h2>Related guides</h2>
<p>Next, <a href="/knowledgebase?thread=connect-and-verify">connect and verify</a>. If something is off, <a href="/knowledgebase?thread=troubleshooting-common-mistakes">troubleshoot common problems</a>. For a machine with no screen, <a href="/knowledgebase?thread=no-gui-clients">run the client without a GUI</a>.</p>
',
    'published', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM knowledgebase_topics AS topic
WHERE topic.topic_key = 'install-client'
  AND NOT EXISTS (
      SELECT 1
      FROM knowledgebase_articles AS existing_article
      WHERE existing_article.topic_id = topic.id
        AND existing_article.locale = 'en'
  );

INSERT INTO knowledgebase_articles (
    topic_id, locale, author_user_id, title, excerpt, body_html,
    status, published_at, created_at, updated_at
)
SELECT
    topic.id, 'en', NULL, 'Create Your First Forward', 'Choose a route package, enter the destination, save, and identify the assigned local port.', '<p>A forward is one line you draw on the panel: where your game or service lives, and which local port your device should use to reach it.</p>

<h2>What you will accomplish</h2>
<p>One saved forward with an assigned local port. The next guide needs that port, so write it down.</p>

<h2>Before you begin</h2>
<ul>
<li>An active plan, so the panel lets you save a forward.</li>
<li>The address and port your game or service really listens on.</li>
<li>Which transport it needs: TCP, UDP, or both. Games are usually UDP.</li>
</ul>

<h2>Steps</h2>
<ol>
<li><strong>Open My Services and click New forward on your package.</strong> The Create Forward button on the service details page opens the same form.</li>
<li><strong>Pick a Forward Route.</strong> Take the line closest to the destination. Every line uses ultra, so there is nothing else to decide.</li>
<li><strong>Fill in Target Host and Target Port.</strong> This is the address the game itself connects to, not the address of the site you downloaded it from.</li>
<li><strong>Choose the Transport</strong> your service needs: TCP, UDP, or TCP + UDP.</li>
<li><strong>Click Create Forward.</strong> Leave Local Listen Port empty so the panel assigns one, unless you have a reason to pick it yourself.</li>
<li><strong>Write down the assigned local port.</strong> After saving you land on the service details page; the new forward is in the Forward Rules table and the Local Listen column is its port.</li>
</ol>
<pre class="mermaid">
flowchart TD
accTitle: First forward form path
accDescr: Open an active plan, pick the forward route, fill in the destination, save, then note the assigned local port
A[Open an active plan] --> B[Pick a Forward Route]
B --> C[Fill in Target Host and Target Port]
C --> D[Choose the Transport]
D --> E[Save]
E --> F[Wait until the forward is ready]
F --> G[Note the assigned local port]
</pre>
<p>The form only asks for the route, the destination, the transport, and the local port. The rest is filled in for you.</p>
<figure><img src="/assets/knowledgebase/screenshots/tenant/02-forward-form-en.png" width="1440" height="960" alt="Create Forward form with the route, target host, target port and transport fields"><figcaption>The Create Forward form. Only four fields need your attention.</figcaption></figure>

<h2>How to know it worked</h2>
<p>In Forward Rules the row reads <strong>Running</strong>, Local Listen has a number, and Endpoint Status no longer says <strong>No endpoint assigned yet</strong>. Then check that the device that will carry this forward is online on the Clients page.</p>

<h2>If it did not work</h2>
<p><strong>Stop</strong> if the panel refuses to save. Three usual reasons: the plan is not active any more, the target host or port is not right, or the local port you typed is already in use - clear it and let the panel suggest one again.</p>

<h2>Related guides</h2>
<p>Next, <a href="/knowledgebase?thread=connect-and-verify">connect and verify</a>. If the app is not installed yet, start with <a href="/knowledgebase?thread=install-client">install the client</a>.</p>
',
    'published', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM knowledgebase_topics AS topic
WHERE topic.topic_key = 'getting-started-first-forward-checklist'
  AND NOT EXISTS (
      SELECT 1
      FROM knowledgebase_articles AS existing_article
      WHERE existing_article.topic_id = topic.id
        AND existing_article.locale = 'en'
  );

INSERT INTO knowledgebase_articles (
    topic_id, locale, author_user_id, title, excerpt, body_html,
    status, published_at, created_at, updated_at
)
SELECT
    topic.id, 'en', NULL, 'Connect and Verify', 'Choose the correct client address, test the service, and confirm that the route is working.', '<p>The forward is saved and the app is online. The last step is telling the game where to connect.</p>

<h2>What you will accomplish</h2>
<p>The game pointed at the client, plus proof that traffic is really flowing.</p>

<h2>Before you begin</h2>
<ul>
<li>A saved forward and the local port the panel assigned to it.</li>
<li>The app installed and signed in on the device that runs the client.</li>
<li>A few minutes to test while the game is open.</li>
</ul>

<h2>Steps</h2>
<ol>
<li><strong>Ask one question: does the game run on the same device as the client?</strong> The answer decides the address you type.</li>
<li><strong>Same device:</strong> use <code>127.0.0.1</code> and the assigned local port. This is the common case, and it stays loopback-only, so nothing else on your network can reach it.</li>
<li><strong>Another device:</strong> use a reachable Windows or Linux client-host address with the same port, and allow that port through the firewall, security group, or NAT between the two.</li>
<li><strong>Start the game and connect.</strong> Then look at the forward row while you play.</li>
</ol>
<pre class="mermaid">
flowchart TD
accTitle: Choosing the client address
accDescr: Check where the game runs, then use loopback or the client host address
A[Where does the game run?] -- Same device as the client --> B[Use 127.0.0.1 and the local port]
A -- Another device --> C[Use the client host address]
B --> D[Start the game]
C --> D
</pre>
<p>Same device keeps the port on loopback only. Another device needs one allowed path through the network in between.</p>
<figure><img src="/assets/knowledgebase/screenshots/tenant/04-forward-result-en.png" width="1440" height="960" alt="Forward Rules table on the service details page with the local port, target, transport and usage"><figcaption>The forward row shows the local port and the traffic it carries.</figcaption></figure>

<h2>How to know it worked</h2>
<p>Two checks. The app shows the line as running, and the forward row shows incoming traffic while you play. On Android the client runs on the phone itself, so the game there always uses <code>127.0.0.1</code> too.</p>

<h2>If it did not work</h2>
<p><strong>Stop</strong> and check the address first. In order of likelihood: the port does not match the assigned local port; a firewall or NAT rule blocks the another-device case; the target service is down - test the target service from its own host and read the target logs. Test the actual application protocol, not a ping.</p>

<h2>Related guides</h2>
<p>Something still off? <a href="/knowledgebase?thread=troubleshooting-common-mistakes">Troubleshoot common problems</a>. Need the port again? <a href="/knowledgebase?thread=getting-started-first-forward-checklist">Create your first forward</a>.</p>
',
    'published', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM knowledgebase_topics AS topic
WHERE topic.topic_key = 'connect-and-verify'
  AND NOT EXISTS (
      SELECT 1
      FROM knowledgebase_articles AS existing_article
      WHERE existing_article.topic_id = topic.id
        AND existing_article.locale = 'en'
  );

INSERT INTO knowledgebase_articles (
    topic_id, locale, author_user_id, title, excerpt, body_html,
    status, published_at, created_at, updated_at
)
SELECT
    topic.id, 'en', NULL, 'Troubleshoot Common Problems', 'Start from the symptom you can see and follow the shortest safe recovery path.', '<p>When a game cannot connect, it is almost always one of three things. Check them in this order and you are usually back in a minute or two.</p>

<h2>What you will accomplish</h2>
<p>Find which of the three usual causes is yours, fix it, and get back into the game.</p>

<h2>Before you begin</h2>
<ul>
<li>The panel open, and the app running on the device you play on.</li>
<li>The guide you followed, in case you need the local port again.</li>
</ul>

<h2>Steps</h2>
<ol>
<li><strong>Is the client online?</strong> The online devices list at the bottom of the Clients page should show this device. If it does not, restart the app, wait a few seconds and reload; if the online limit is full, kick a device you no longer use.</li>
<li><strong>Is the address correct?</strong> Same device as the client means 127.0.0.1 plus the local port; another device means the client host address.</li>
<li><strong>Is the port allowed?</strong> Only the port you actually use has to be open through the firewall, security group, or NAT.</li>
<li><strong>Are the forward and route healthy?</strong> In Forward Rules the row should read Running and Endpoint Status should not stay on "No endpoint assigned yet"; the target service should answer when you test it from its own host.</li>
<li><strong>Still stuck?</strong> Send support the forward ID, the device you used, and roughly when it failed.</li>
</ol>
<pre class="mermaid">
flowchart TD
accTitle: Working from the symptom
accDescr: Start from the failing game and check the client sync, the address, then the target service
A[The game cannot connect] --> B[Is the client in sync?]
B -- No --> C[Restart the app and try again]
B -- Yes --> D[Is the address and port correct?]
D -- No --> E[Use 127.0.0.1 or the client host address]
E --> C
D -- Yes --> F[Test the target service from its own host]
F --> G[Contact support with the forward ID]
</pre>
<p>Work down the list once, in order. Skipping a step usually means coming back to it later.</p>
<figure><img src="/assets/knowledgebase/screenshots/tenant/05-online-devices-en.png" width="1440" height="960" alt="Online devices list on the Clients page with each device IP, system and app version"><figcaption>Online devices lists every signed-in device; if yours is missing, the forward will not work.</figcaption></figure>

<h2>How to know it worked</h2>
<p>The app shows the line as running, and Usage this cycle in Forward Rules goes up again while you play.</p>

<h2>If it did not work</h2>
<p><strong>Stop</strong> before changing anything else. Do not change settings at random - it hides the real cause and makes the next round slower. If all four checks are clean, contact support; that is the fastest path from there.</p>

<h2>Related guides</h2>
<p><a href="/knowledgebase?thread=connect-and-verify">Connect and verify</a>, or <a href="/knowledgebase?thread=getting-started-first-forward-checklist">create your first forward</a> if the row itself looks wrong.</p>
',
    'published', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM knowledgebase_topics AS topic
WHERE topic.topic_key = 'troubleshooting-common-mistakes'
  AND NOT EXISTS (
      SELECT 1
      FROM knowledgebase_articles AS existing_article
      WHERE existing_article.topic_id = topic.id
        AND existing_article.locale = 'en'
  );

INSERT INTO knowledgebase_articles (
    topic_id, locale, author_user_id, title, excerpt, body_html,
    status, published_at, created_at, updated_at
)
SELECT
    topic.id, 'en', NULL, 'Glossary', 'Plain-language definitions for the product and network terms used in the guides.', '<p>One short line for each word the other guides use. Open this page whenever a word is unclear, and close it again.</p>

<h2>What you will accomplish</h2>
<p>You will know what each word in the guides means, without reading anything technical.</p>

<h2>Before you begin</h2>
<ul>
<li>Nothing to prepare. This page is meant to sit in a second tab.</li>
<li>If a word you need is missing, ask support and it gets added.</li>
</ul>

<h2>Steps</h2>
<ol>
<li><strong>Find the word</strong> in the table below.</li>
<li><strong>Read the one-line meaning.</strong> Stop there if it already makes sense.</li>
<li><strong>Open the guide named next to it</strong> when you want to use the word, not just read it.</li>
</ol>
<table>
<thead>
<tr><th>Word</th><th>What it means</th></tr>
</thead>
<tbody>
<tr><td><strong>Active plan</strong></td><td>The plan you paid for that is still valid. Forwards only run while a plan is active.</td></tr>
<tr><td><strong>Forward</strong></td><td>One line you draw on the panel: a destination goes in, a local port comes out.</td></tr>
<tr><td><strong>Forward Route</strong></td><td>The line your traffic leaves from. Pick the one closest to the destination.</td></tr>
<tr><td><strong>Target Host</strong></td><td>The address of the game or service you want to reach.</td></tr>
<tr><td><strong>Target Port</strong></td><td>The port that address listens on.</td></tr>
<tr><td><strong>Local Listen Port</strong></td><td>The port on your own device that the panel hands you, shown as Local Listen in Forward Rules. Your game uses this one.</td></tr>
<tr><td><strong>Transport</strong></td><td>Whether the traffic is TCP, UDP, or both. Games are usually UDP.</td></tr>
<tr><td><strong>Client</strong></td><td>The app that carries the line on your device.</td></tr>
<tr><td><strong>Running</strong></td><td>The state the app shows when the line is up.</td></tr>
<tr><td><strong>Online devices</strong></td><td>The devices currently signed in, listed on the Clients page. A device in the list means its client is healthy.</td></tr>
<tr><td><strong>Ultra</strong></td><td>How every line is carried. It keeps the route steady while you play.</td></tr>
<tr><td><strong>Application verification</strong></td><td>Testing the game itself, not a ping.</td></tr>
</tbody>
</table>

<h2>How to know it worked</h2>
<p>You read a guide and no word stopped you.</p>

<h2>If it did not work</h2>
<p><strong>Stop</strong> and ask support when two guides seem to disagree. Do not guess at settings - a wrong guess costs more time than the question.</p>

<h2>Related guides</h2>
<p>Start at <a href="/knowledgebase?thread=getting-started-five-minute-overview">get connected in five minutes</a>, and keep <a href="/knowledgebase?thread=troubleshooting-common-mistakes">troubleshoot common problems</a> nearby.</p>
',
    'published', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM knowledgebase_topics AS topic
WHERE topic.topic_key = 'glossary'
  AND NOT EXISTS (
      SELECT 1
      FROM knowledgebase_articles AS existing_article
      WHERE existing_article.topic_id = topic.id
        AND existing_article.locale = 'en'
  );

INSERT INTO knowledgebase_articles (
    topic_id, locale, author_user_id, title, excerpt, body_html,
    status, published_at, created_at, updated_at
)
SELECT
    topic.id, 'en', NULL, 'Run the Client Without a GUI', 'Install the windowless client on Windows or macOS as a service or daemon, verify it, and remove it again.', '<h2>What you will accomplish</h2>
<p>You will set up a computer that keeps your line running on its own — a server, a mini PC, or any machine that stays switched on. Nobody has to log in, and you will not see a window or an icon. You install it once, check that it works, and from then on you manage it with one short command.</p>

<h2>Before you begin</h2>
<ul>
  <li>Your <strong>Client ID</strong> and <strong>Agent Token</strong> from the panel''s <strong>Clients</strong> page. They are the machine''s login — treat them like a password.</li>
  <li>Permission to install software on that machine (an administrator on Windows, your own password on macOS).</li>
  <li>Two minutes. The download is about 80 MB on macOS and 34 MB on Windows.</li>
</ul>

<h2>Steps</h2>
<pre class="mermaid">flowchart TD
accTitle: Setting up a computer without a screen
accDescr: Pick the computer, install the background client, then confirm the line is running in the panel.
 A[Pick the computer] --&gt; B{Which system}
 B -- macOS --&gt; C[Install the app, then run one command]
 B -- Windows --&gt; D[Unpack the download, then run one command]
 C --&gt; E[Check that it says running]
 D --&gt; E
 E --&gt; F[See the device online in the panel]</pre>
<p>The picture is the whole job: choose the system, run one command, then confirm. Everything after that happens in the background.</p>
<figure class="kb-screenshot-figure">
  <img src="/assets/knowledgebase/screenshots/tenant/03-client-install-en.png" width="1440" height="960" alt="Clients page showing the download buttons and this machine''s Client ID and Agent Token">
  <figcaption>Your Client ID and Agent Token are on this page, together with the download for your system.</figcaption>
</figure>
<ol>
  <li><strong>Choose the computer.</strong> It should be one that stays on. It does not need a screen, a keyboard, or anyone logged in.</li>
  <li><strong>Install the app first.</strong> On macOS, open the downloaded disk image and drag <strong>LeiKwan Bridge</strong> into <strong>Applications</strong>. On Windows, unpack the no-GUI download into a folder of your choice.</li>
  <li><strong>Run the installer once.</strong> On macOS, open Terminal and paste the macOS command from the Clients page. On Windows, right-click the unpacked folder and choose <em>Open in Terminal</em> as administrator, then paste the Windows command. Both commands already contain this machine''s identity.</li>
  <li><strong>Check it started.</strong> Type <code>leikwan status</code>. You want to see the line listed as running.</li>
  <li><strong>Look at the panel.</strong> The device appears online on the Clients page, exactly like a computer with the normal app.</li>
  <li><strong>Later, three commands are all you need.</strong> <code>leikwan status</code> to check, <code>leikwan restart</code> if something looks stuck, and <code>leikwan update</code> to move to the newest version, and <code>leikwan update --check</code> if you only want to know whether an update exists.</li>
  <li><strong>To remove it:</strong> run the uninstall command from the same Clients page, or <code>leikwan stop</code> to switch it off for now.</li>
</ol>

<h2>How to know it worked</h2>
<p>Two simple checks. In the panel, this computer shows up as online, the same as any other device. On the computer itself, <code>leikwan status</code> prints the line as running — and it ends with a success code, so a monitoring tool can watch it for you. If you ever want the details, <code>leikwan logs -n 50</code> shows the last 50 lines and <code>leikwan doctor</code> summarises the whole setup.</p>

<h2>If it did not work</h2>
<p><strong>Stop and do not share your Agent Token with anyone — not in chat, tickets, or screenshots.</strong> The most common cause is a mistyped or expired Agent Token, which makes the panel show the device as offline while the log says the login was refused. Copy the Client ID and Agent Token again from the Clients page and run the installer command once more. If the device is online in the panel but a line still shows as stopped, wait half a minute — the panel decides which lines should run, and the computer follows within a few seconds. Still stuck? Send support the output of <code>leikwan doctor</code>, which contains no secrets.</p>

<h2>Related guides</h2>
<ul>
  <li><a href="/knowledgebase?thread=install-client">Install the Client</a></li>
  <li><a href="/knowledgebase?thread=connect-and-verify">Connect and Verify</a></li>
  <li><a href="/knowledgebase?thread=troubleshooting-common-mistakes">Troubleshoot Common Problems</a></li>
</ul>
',
    'published', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM knowledgebase_topics AS topic
WHERE topic.topic_key = 'no-gui-clients'
  AND NOT EXISTS (
      SELECT 1
      FROM knowledgebase_articles AS existing_article
      WHERE existing_article.topic_id = topic.id
        AND existing_article.locale = 'en'
  );

INSERT INTO knowledgebase_articles (
    topic_id, locale, author_user_id, title, excerpt, body_html,
    status, published_at, created_at, updated_at
)
SELECT
    topic.id, 'zh-Hans', NULL, '五分钟完成首次连接', '按最短路径完成套餐、转发、客户端安装和连接验证。', '<h2>你将完成什么</h2>
<p>你会从"套餐已生效"走到"线路可用"：在控制台建一条转发、装上客户端、连接，然后看到线路在运行。大约五分钟，而且你不需要选择任何连接类型 —— 所有线路都用 ultra，它做的就是在你打游戏时把线路保持稳定。</p>

<h2>开始前准备</h2>
<ul>
  <li>一个使用中的套餐，控制台才会允许你创建转发。</li>
  <li>想加速的游戏或程序，以及它连接的地址（游戏里的服务器列表通常能看到）。</li>
  <li>运行客户端的设备：Windows、macOS、Android 或 Linux 都可以，手机和电脑一样能用。</li>
  <li>你的控制台账号，以及大约五分钟。</li>
</ul>

<h2>操作步骤</h2>
<pre class="mermaid">flowchart TD
accTitle: 从套餐到可用线路
accDescr: 先创建一条转发，装上客户端并连接，再把游戏指向本机端口。
A[打开控制台] --&gt; B[新建一条转发]
B --&gt; C[安装客户端]
C --&gt; D[登录并连接]
D --&gt; E[把游戏指向本机端口]
E --&gt; F[开始游戏]</pre>
<p>五步，下面每步都只有几行说明。这里没有任何需要你判断的技术选项。</p>
<figure class="kb-screenshot-figure">
  <img src="/assets/knowledgebase/screenshots/tenant/01-overview-zh-hans.png" width="1440" height="960" alt="控制台总览页，显示使用中的套餐、转发规则数量和三步开始使用">
  <figcaption>起点就在这里：登录后的「总览」页会告诉你下一步该做什么。</figcaption>
</figure>
<ol>
  <li><strong>打开控制台，确认套餐在使用中。</strong>「总览」页的「最近的套餐」会显示状态；如果显示已到期或已暂停，就没法新建转发。</li>
  <li><strong>新建一条转发。</strong>进入「我的服务」，在套餐卡片上点「新建转发」。选择离游戏服务器最近的线路，填入游戏连接的目标地址和目标端口，本地监听端口留空让系统分配，然后保存。把这个本地端口记下来 —— 第 5 步要用。</li>
  <li><strong>安装客户端</strong>到你玩游戏的设备上：在「客户端」页面，Windows 点「下载安装包」，Android 点「下载 APK」，Linux 复制那一条安装命令运行。用控制台账号登录。</li>
  <li><strong>连接。</strong>客户端里会列出你刚建的转发，点连接，等到显示 running。</li>
  <li><strong>把游戏指向客户端。</strong>游戏里凡是要填服务器地址的地方，填 <code>127.0.0.1</code> 和第 2 步记下的本地端口，然后启动游戏。</li>
  <li><strong>开始游戏，之后再回控制台看一眼。</strong>你玩的时候，那条转发的「本期用量」会增加。</li>
</ol>

<h2>如何确认成功</h2>
<p>按顺序看三个地方：客户端里线路显示为 running；你游戏时控制台上那条转发的「本期用量」在增加；游戏本身稳定，不会在一局中途突然卡顿。如果游戏能进但用量一直是 0，几乎都是本地端口填错了 —— 回到第 5 步检查。</p>

<h2>如果没有成功</h2>
<p><strong>先停止</strong>反复提交表单，先确认套餐状态 —— 控制台不让你保存转发，最常见的原因就是套餐到期。第二常见的是目标填错：要填游戏真正连接的地址，不是你下载游戏的网站地址。第三是本地端口：游戏必须使用系统分配的那个端口。其它情况（客户端连不上、线路老是掉、游戏进不去服务器）都写在下面的排查指南里。</p>

<h2>相关指南</h2>
<ul>
  <li><a href="/knowledgebase?thread=getting-started-first-forward-checklist">创建第一条转发</a></li>
  <li><a href="/knowledgebase?thread=install-client">安装客户端</a></li>
  <li><a href="/knowledgebase?thread=connect-and-verify">连接并验证</a></li>
</ul>
',
    'published', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM knowledgebase_topics AS topic
WHERE topic.topic_key = 'getting-started-five-minute-overview'
  AND NOT EXISTS (
      SELECT 1
      FROM knowledgebase_articles AS existing_article
      WHERE existing_article.topic_id = topic.id
        AND existing_article.locale = 'zh-Hans'
  );

INSERT INTO knowledgebase_articles (
    topic_id, locale, author_user_id, title, excerpt, body_html,
    status, published_at, created_at, updated_at
)
SELECT
    topic.id, 'zh-Hans', NULL, '安装客户端', '在 Windows、Android 或 Linux 上安装并登录，然后确认客户端在线。', '<p>在你玩游戏的那台设备上装一次客户端，登录之后让它一直开着就行。以后只要偶尔看一眼线路还在不在。</p>

<h2>你将完成什么</h2>
<p>客户端装好、用控制台账号登录，并且在控制台「客户端」页面的「在线设备」里能看到它。</p>

<h2>开始前准备</h2>
<ul>
<li>你的控制台邮箱和密码。</li>
<li>你玩游戏用的设备。客户端支持 Windows、macOS、Android 和 Linux。</li>
<li>手机或平板需要 Android 8.0 以上。</li>
<li>Linux 需要 Debian 11、12 或 13（x86_64 或 arm64 都可以），并且能用 root 执行命令。</li>
</ul>

<h2>操作步骤</h2>
<ol>
<li><strong>打开「客户端」页面。</strong>需要的东西都在「下载与安装」这一块里。</li>
<li><strong>安装客户端。</strong>Windows 点「下载安装包」；Android 点「下载 APK」；macOS 点对应芯片的下载按钮；Linux 点「复制命令」，再在目标机器上执行这条命令。</li>
<li><strong>登录。</strong>用控制台的邮箱和密码。进去之后客户端会显示 <strong>Connection successful!</strong>。</li>
<li><strong>让它一直开着。</strong>客户端显示 <strong>Connected</strong>，开关显示 <strong>Running</strong>。</li>
</ol>
<pre class="mermaid">
flowchart TD
accTitle: 客户端安装路径
accDescr: 按设备系统选择安装方式，登录后确认客户端在线
A[选择设备] --> B[操作系统]
B --> C[下载 Windows 安装包]
B --> D[下载 Android APK]
B --> E[复制 Linux 安装命令]
C --> F[登录]
D --> F
E --> F
F --> G[客户端显示 Connected 和 Running]
</pre>
<p>几种设备最后都停在同一处：客户端已经登录，控制台的「在线设备」里能看到它。</p>
<figure><img src="/assets/knowledgebase/screenshots/tenant/03-client-install-zh-hans.png" width="1440" height="960" alt="客户端页面的下载与安装区域，包括 Linux 安装命令和 Windows 下载安装包按钮"><figcaption>下载入口和 Linux 安装命令都在「客户端」页面的「下载与安装」里。</figcaption></figure>

<h2>如何确认成功</h2>
<p>看两处。客户端显示 <strong>Connected</strong>；「客户端」页面底部的「在线设备」里出现这台设备（IP、系统和客户端版本）。</p>

<h2>如果没有成功</h2>
<p>如果一直停在 <strong>Connecting...</strong>，<strong>先停止</strong>，重新登录一次。按可能性排：密码不对，或者账号还没生效；同时在线的设备已经达到套餐上限（看页面上的「最大在线」）；Android 把客户端在后台休眠了；Linux 命令没有用 root 执行。</p>

<h2>相关指南</h2>
<p>接着做<a href="/knowledgebase?thread=connect-and-verify">连接并验证</a>。装好还没有转发，先看<a href="/knowledgebase?thread=getting-started-first-forward-checklist">创建第一条转发</a>。有问题可以看<a href="/knowledgebase?thread=troubleshooting-common-mistakes">排查常见问题</a>；没有屏幕的机器看<a href="/knowledgebase?thread=no-gui-clients">无界面运行客户端</a>。</p>
',
    'published', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM knowledgebase_topics AS topic
WHERE topic.topic_key = 'install-client'
  AND NOT EXISTS (
      SELECT 1
      FROM knowledgebase_articles AS existing_article
      WHERE existing_article.topic_id = topic.id
        AND existing_article.locale = 'zh-Hans'
  );

INSERT INTO knowledgebase_articles (
    topic_id, locale, author_user_id, title, excerpt, body_html,
    status, published_at, created_at, updated_at
)
SELECT
    topic.id, 'zh-Hans', NULL, '创建第一条转发', '选择线路方案、填写目标地址并记下系统分配的本地端口。', '<p>一条转发就是你在控制台上画的一条线：你的游戏或服务在哪里，以及本机用哪个端口去连它。</p>

<h2>你将完成什么</h2>
<p>一条保存好的转发，加上一个分配给你的本地端口。下一篇指南要用这个端口，先记下来。</p>

<h2>开始前准备</h2>
<ul>
<li>一个使用中的套餐，控制台才允许保存转发。</li>
<li>你的游戏或服务真正监听的地址和端口。</li>
<li>它需要哪种协议：TCP、UDP 还是两者都要。游戏一般是 UDP。</li>
</ul>

<h2>操作步骤</h2>
<ol>
<li><strong>打开「我的服务」，在套餐卡片上点「新建转发」。</strong>在服务详情页里点「新建转发」也是同一个表单。</li>
<li><strong>选择线路。</strong>挑离目标最近的那条线路。所有线路都用 ultra，这里不需要再决定别的。</li>
<li><strong>填写目标地址和目标端口。</strong>这里要填游戏自己连的地址，不是你下载游戏的网站地址。</li>
<li><strong>选择协议</strong>，也就是你的服务需要的传输方式：TCP、UDP 或 TCP + UDP。</li>
<li><strong>点「新建转发」保存。</strong>本地监听端口留空就行，系统会自动分配，除非你有特别的理由要指定。</li>
<li><strong>记下分配给你的本地端口。</strong>保存后会回到服务详情页，新转发出现在「转发规则」表里，「本地端口」一列就是它。</li>
</ol>
<pre class="mermaid">
flowchart TD
accTitle: 第一条转发的表单路径
accDescr: 打开使用中的套餐，选择线路，填写目标和协议，保存后记下分配的本地端口
A[打开我的服务] --> B[点新建转发]
B --> C[选择线路]
C --> D[填写目标地址和目标端口]
D --> E[选择协议]
E --> F[保存]
F --> G[记下分配的本地端口]
</pre>
<p>表单只问线路、目标、协议和本地端口，其余都替你填好了。</p>
<figure><img src="/assets/knowledgebase/screenshots/tenant/02-forward-form-zh-hans.png" width="1440" height="960" alt="新建转发表单，包含线路、目标地址、目标端口、本地监听端口和协议"><figcaption>「新建转发」表单，真正要你决定的只有四个字段。</figcaption></figure>

<h2>如何确认成功</h2>
<p>在「转发规则」表里，这一行的状态是「运行中」，「本地端口」已经有数字，「节点状态」不再一直显示「正在分配节点」。接着去「客户端」页面，确认准备承载这条转发的设备出现在「在线设备」里。</p>

<h2>如果没有成功</h2>
<p>控制台不让保存时，<strong>先停止</strong>，别反复点。三个常见原因：套餐已经到期；目标地址或端口填错；你填的本地端口已经被占用——清空它，让系统重新分配一个。如果「线路」下拉框是空的，说明你的套餐还没有开放线路，请联系客服。</p>

<h2>相关指南</h2>
<p>接着做<a href="/knowledgebase?thread=connect-and-verify">连接并验证</a>。客户端还没装的话，先看<a href="/knowledgebase?thread=install-client">安装客户端</a>。</p>
',
    'published', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM knowledgebase_topics AS topic
WHERE topic.topic_key = 'getting-started-first-forward-checklist'
  AND NOT EXISTS (
      SELECT 1
      FROM knowledgebase_articles AS existing_article
      WHERE existing_article.topic_id = topic.id
        AND existing_article.locale = 'zh-Hans'
  );

INSERT INTO knowledgebase_articles (
    topic_id, locale, author_user_id, title, excerpt, body_html,
    status, published_at, created_at, updated_at
)
SELECT
    topic.id, 'zh-Hans', NULL, '连接并验证', '选择正确的客户端地址，测试服务并确认转发已经工作。', '<p>转发已经保存，客户端也在线了。最后一步是告诉游戏该连哪里。</p>

<h2>你将完成什么</h2>
<p>让游戏指向客户端，并且确认流量真的在走。</p>

<h2>开始前准备</h2>
<ul>
<li>一条保存好的转发，以及系统分配的本地端口。</li>
<li>运行客户端的那台设备上，客户端已经装好并登录。</li>
<li>游戏开着的时候留几分钟做测试。</li>
</ul>

<h2>操作步骤</h2>
<ol>
<li><strong>先问一个问题：游戏和客户端在同一台设备上吗？</strong>答案决定你要填哪个地址。</li>
<li><strong>同一台设备：</strong>用 <code>127.0.0.1</code> 加分配的本地端口。这是最常见的情况，而且只监听回环地址，局域网里其他设备碰不到它。</li>
<li><strong>不同设备：</strong>用可访问的 Windows 或 Linux 客户端主机地址加同一个端口，并在两台设备之间的防火墙、安全组或 NAT 上放行这个端口。</li>
<li><strong>启动游戏并连接。</strong>玩的时候看一眼那条转发。</li>
</ol>
<pre class="mermaid">
flowchart TD
accTitle: 选择客户端连接地址
accDescr: 先确认游戏在哪台设备上运行，再决定用回环地址还是客户端主机地址
A[游戏在哪台设备上运行？] -- 与客户端同机 --> B[使用 127.0.0.1 和本地端口]
A -- 另一台设备 --> C[使用客户端主机地址]
B --> D[启动游戏]
C --> D
</pre>
<p>同一台设备时端口只留在回环地址上；跨设备时需要在中间的网络里放行一条路径。</p>
<figure><img src="/assets/knowledgebase/screenshots/tenant/04-forward-result-zh-hans.png" width="1440" height="960" alt="服务详情页的转发规则表，显示本地端口、目标、协议和本期用量"><figcaption>服务详情页的「转发规则」里能看到本地端口和它的本期用量。</figcaption></figure>

<h2>如何确认成功</h2>
<p>看两处。客户端显示这条线路 running；你玩的时候，「转发规则」里这条转发的「本期用量」在增加。Android 上客户端就跑在手机里，所以那边的游戏同样用 <code>127.0.0.1</code>。</p>

<h2>如果没有成功</h2>
<p><strong>先停止</strong>，先核对地址。按可能性排：端口和分配的本地端口不一致；跨设备时被防火墙或 NAT 挡住了；目标服务本身没起来——从目标服务自己的主机测试目标服务，再看目标日志。测试时要测实际应用协议，不要只 ping。</p>

<h2>相关指南</h2>
<p>还是不通就看<a href="/knowledgebase?thread=troubleshooting-common-mistakes">排查常见问题</a>。忘了端口是多少，回<a href="/knowledgebase?thread=getting-started-first-forward-checklist">创建第一条转发</a>看一眼。</p>
',
    'published', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM knowledgebase_topics AS topic
WHERE topic.topic_key = 'connect-and-verify'
  AND NOT EXISTS (
      SELECT 1
      FROM knowledgebase_articles AS existing_article
      WHERE existing_article.topic_id = topic.id
        AND existing_article.locale = 'zh-Hans'
  );

INSERT INTO knowledgebase_articles (
    topic_id, locale, author_user_id, title, excerpt, body_html,
    status, published_at, created_at, updated_at
)
SELECT
    topic.id, 'zh-Hans', NULL, '排查常见问题', '从看得见的症状开始，按最短且安全的顺序恢复连接。', '<p>游戏连不上的时候，几乎都是这三个原因之一。按顺序查一遍，通常一两分钟就能回到游戏里。</p>

<h2>你将完成什么</h2>
<p>找出这三个常见原因里属于你的那一个，修好它，重新进游戏。</p>

<h2>开始前准备</h2>
<ul>
<li>打开控制台，并且保证你玩游戏那台设备上的客户端在运行。</li>
<li>你之前跟着做的那篇指南，万一要再查一次本地端口。</li>
</ul>

<h2>操作步骤</h2>
<ol>
<li><strong>客户端在线吗？</strong>「客户端」页面底部的「在线设备」里应该能看到这台设备。没有的话，重启客户端，等几秒再刷新页面；如果「最大在线」已经满了，先在列表里把不用的设备「踢下线」。</li>
<li><strong>地址是否正确？</strong>和客户端同一台设备就用 127.0.0.1 加本地端口；不同设备就用客户端主机地址。</li>
<li><strong>端口已放行吗？</strong>只需要放行你真正用的那个端口，防火墙、安全组或 NAT 上放行一条就够。</li>
<li><strong>转发和线路正常吗？</strong>在服务详情页的「转发规则」里，这一行的状态应该是「运行中」，「节点状态」不应该一直停在「正在分配节点」；从目标服务自己的主机测试目标服务时也应该有回应。</li>
<li><strong>还是不行？</strong>把转发的编号、用的是哪台设备、出问题的大概时间一起发给客服。</li>
</ol>
<pre class="mermaid">
flowchart TD
accTitle: 从症状开始排查
accDescr: 从游戏连不上开始，依次检查客户端在线、地址，最后检查目标服务
A[游戏连不上] --> B[客户端在线吗？]
B -- 否 --> C[重启客户端再试]
B -- 是 --> D[地址和端口正确吗？]
D -- 否 --> E[改用 127.0.0.1 或客户端主机地址]
E --> C
D -- 是 --> F[从目标服务自己的主机测试目标服务]
F --> G[带上转发编号联系客服]
</pre>
<p>从头到尾走一遍，别跳步。跳过的步骤通常最后还要回来补。</p>
<figure><img src="/assets/knowledgebase/screenshots/tenant/05-online-devices-zh-hans.png" width="1440" height="960" alt="客户端页面的在线设备列表，显示设备 IP、系统和客户端版本"><figcaption>「在线设备」列出当前登录的每台设备；设备不在这里，转发就不会通。</figcaption></figure>

<h2>如何确认成功</h2>
<p>客户端显示这条线路 running，你玩的时候「转发规则」里的「本期用量」又开始增加。</p>

<h2>如果没有成功</h2>
<p>在改别的东西之前，<strong>先停止</strong>。不要随手改一堆设置——那样只会盖住真正的原因，让下一次排查更慢。上面四项都没问题的话，直接联系客服，这是从这里出发最快的路。</p>

<h2>相关指南</h2>
<p>回到<a href="/knowledgebase?thread=connect-and-verify">连接并验证</a>；如果那一行本身看着就不对，看<a href="/knowledgebase?thread=getting-started-first-forward-checklist">创建第一条转发</a>。</p>
',
    'published', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM knowledgebase_topics AS topic
WHERE topic.topic_key = 'troubleshooting-common-mistakes'
  AND NOT EXISTS (
      SELECT 1
      FROM knowledgebase_articles AS existing_article
      WHERE existing_article.topic_id = topic.id
        AND existing_article.locale = 'zh-Hans'
  );

INSERT INTO knowledgebase_articles (
    topic_id, locale, author_user_id, title, excerpt, body_html,
    status, published_at, created_at, updated_at
)
SELECT
    topic.id, 'zh-Hans', NULL, '名词解释', '用通俗语言解释指南中出现的产品和网络术语。', '<p>其他指南里出现的词，这里每个用一句话说清楚。遇到看不懂的词就打开这一页，看完再关掉。</p>

<h2>你将完成什么</h2>
<p>指南里的每个词你都能看懂，不需要读任何技术资料。</p>

<h2>开始前准备</h2>
<ul>
<li>不用准备什么。这一页适合一直开在另一个标签页里。</li>
<li>少了你要查的词，告诉支持，我们会补上。</li>
</ul>

<h2>操作步骤</h2>
<ol>
<li><strong>在下面的表里找到那个词。</strong></li>
<li><strong>读那一句话的解释。</strong>看懂就可以停下了。</li>
<li><strong>想真正用起来，就去打开旁边对应的指南。</strong></li>
</ol>
<table>
<thead>
<tr><th>词</th><th>意思</th></tr>
</thead>
<tbody>
<tr><td><strong>使用中的套餐</strong></td><td>你付过钱而且还在有效期内的套餐。只有套餐有效时转发才会运行。</td></tr>
<tr><td><strong>转发</strong></td><td>你在控制台上画的一条线：一头是目标，另一头是本机端口。</td></tr>
<tr><td><strong>线路</strong></td><td>流量从哪条线路出去。挑离目标最近的那条。</td></tr>
<tr><td><strong>目标地址</strong></td><td>你要连接的游戏或服务的地址。</td></tr>
<tr><td><strong>目标端口</strong></td><td>那个地址监听的端口。</td></tr>
<tr><td><strong>本地监听端口</strong></td><td>系统分给你自己设备的端口，在「转发规则」里显示为「本地端口」。游戏用这个。</td></tr>
<tr><td><strong>协议</strong></td><td>流量是 TCP、UDP 还是两者都要。游戏一般是 UDP。</td></tr>
<tr><td><strong>客户端</strong></td><td>在你设备上承载这条线路的应用。</td></tr>
<tr><td><strong>Running</strong></td><td>线路通了以后客户端显示的状态。</td></tr>
<tr><td><strong>在线设备</strong></td><td>「客户端」页面上当前登录着的设备列表。设备在列表里，说明客户端正常。</td></tr>
<tr><td><strong>最大在线</strong></td><td>你的套餐允许同时登录的设备数。满了以后新设备登录不上，可以把不用的设备踢下线。</td></tr>
<tr><td><strong>ultra</strong></td><td>所有线路都用它承载。它让你玩的时候线路保持稳定。</td></tr>
<tr><td><strong>应用验证</strong></td><td>用游戏本身去测，而不是只 ping 一下。</td></tr>
</tbody>
</table>

<h2>如何确认成功</h2>
<p>你读完一篇指南，中途没有被任何词卡住。</p>

<h2>如果没有成功</h2>
<p>两篇指南看起来说法不一致时，<strong>先停止</strong>，直接问支持。设置不要靠猜——猜错花的时间比问一句多得多。</p>

<h2>相关指南</h2>
<p>先从<a href="/knowledgebase?thread=getting-started-five-minute-overview">五分钟完成首次连接</a>开始，再把<a href="/knowledgebase?thread=troubleshooting-common-mistakes">排查常见问题</a>放在手边。</p>
',
    'published', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM knowledgebase_topics AS topic
WHERE topic.topic_key = 'glossary'
  AND NOT EXISTS (
      SELECT 1
      FROM knowledgebase_articles AS existing_article
      WHERE existing_article.topic_id = topic.id
        AND existing_article.locale = 'zh-Hans'
  );

INSERT INTO knowledgebase_articles (
    topic_id, locale, author_user_id, title, excerpt, body_html,
    status, published_at, created_at, updated_at
)
SELECT
    topic.id, 'zh-Hans', NULL, '无界面运行客户端', '在 Windows 或 macOS 上把无界面客户端安装为服务或守护进程，验证并卸载。', '<h2>你将完成什么</h2>
<p>你会让一台电脑自己把线路跑起来 —— 一台服务器、一台小主机，或者任何长期开机的机器。没有人需要登录，桌面上不会出现窗口或图标。装一次、确认一次，之后用一条短命令管理它。</p>

<h2>开始前准备</h2>
<ul>
  <li>控制台「客户端」页面上的 <strong>客户端 ID</strong> 和 <strong>客户端令牌</strong>，它们就是这台机器的登录凭据，请像密码一样保管。</li>
  <li>在这台机器上安装软件的权限（Windows 需要管理员，macOS 需要你自己的密码）。</li>
  <li>两分钟时间。macOS 下载约 80 MB，Windows 约 34 MB。</li>
</ul>

<h2>操作步骤</h2>
<pre class="mermaid">flowchart TD
accTitle: 不用屏幕的电脑设置流程
accDescr: 选择电脑，安装后台客户端，然后在控制台确认线路已经在运行。
A[选择电脑] --&gt; B{哪个系统}
B -- macOS --&gt; C[先装应用，再运行一条命令]
B -- Windows --&gt; D[解压下载包，再运行一条命令]
C --&gt; E[确认显示 running]
D --&gt; E
E --&gt; F[在控制台看到设备在线]</pre>
<p>整件事就是这张图：选系统、运行一条命令、确认结果。之后的一切都在后台进行。</p>
<figure class="kb-screenshot-figure">
  <img src="/assets/knowledgebase/screenshots/tenant/03-client-install-zh-hans.png" width="1440" height="960" alt="客户端页面，显示下载入口以及这台机器的客户端 ID 与客户端令牌">
  <figcaption>客户端 ID 和客户端令牌在同一页，下载按你的系统选择「无界面版」。</figcaption>
</figure>
<ol>
  <li><strong>挑选电脑。</strong>用一台长期开机的机器，不需要显示器、不需要键盘，也不需要有人登录。</li>
  <li><strong>先安装应用。</strong>macOS：打开下载的磁盘映像，把 <strong>LeiKwan Bridge</strong> 拖进 <strong>应用程序</strong>。Windows：把无界面版下载包解压到一个文件夹。</li>
  <li><strong>运行一次安装命令。</strong>macOS：打开「终端」，粘贴客户端页面「无界面版」里「复制 macOS 命令」得到的命令。Windows：在解压出来的文件夹上右键，以管理员身份打开终端，粘贴「复制 Windows 命令」得到的命令。两条命令里都已经带了这台机器的身份。</li>
  <li><strong>确认已经启动。</strong>输入 <code>leikwan status</code>，看到转发显示为 running 就对了。</li>
  <li><strong>回到控制台看一眼。</strong>这台设备会像装了普通客户端的电脑一样出现在「在线设备」里。</li>
  <li><strong>以后只需要三条命令。</strong>查看用 <code>leikwan status</code>，觉得卡住用 <code>leikwan restart</code>，升级用 <code>leikwan update</code>；如果只想看看有没有新版本，用 <code>leikwan update --check</code>。</li>
  <li><strong>需要卸载时：</strong>运行同一个客户端页面上的卸载命令，或者先用 <code>leikwan stop</code> 暂时关掉它。</li>
</ol>

<h2>如何确认成功</h2>
<p>两个简单检查。控制台「在线设备」里能看到这台电脑，和其他设备一样；在电脑上运行 <code>leikwan status</code>，线路显示为 running，并且命令以成功状态结束，所以监控工具也可以直接用它。想看细节时，<code>leikwan logs -n 50</code> 显示最近 50 行日志，<code>leikwan doctor</code> 一次列清整体状态。</p>

<h2>如果没有成功</h2>
<p><strong>先停止把客户端令牌发给任何人 —— 聊天、工单、截图都不行。</strong>最常见的原因是客户端令牌抄错或已失效：控制台里看不到这台设备在线，日志里会写着登录被拒绝。回到客户端页面重新复制客户端 ID 与客户端令牌，再运行一次安装命令即可。如果控制台显示设备在线、但某条转发仍是停止状态，等半分钟 —— 转发由控制台决定，电脑会在几秒内跟上。仍然不通，就把 <code>leikwan doctor</code> 的输出发给客服，那份内容不含任何机密。</p>

<h2>相关指南</h2>
<ul>
  <li><a href="/knowledgebase?thread=install-client">安装客户端</a></li>
  <li><a href="/knowledgebase?thread=connect-and-verify">连接与验证</a></li>
  <li><a href="/knowledgebase?thread=troubleshooting-common-mistakes">常见问题排查</a></li>
</ul>
',
    'published', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM knowledgebase_topics AS topic
WHERE topic.topic_key = 'no-gui-clients'
  AND NOT EXISTS (
      SELECT 1
      FROM knowledgebase_articles AS existing_article
      WHERE existing_article.topic_id = topic.id
        AND existing_article.locale = 'zh-Hans'
  );

INSERT INTO knowledgebase_articles (
    topic_id, locale, author_user_id, title, excerpt, body_html,
    status, published_at, created_at, updated_at
)
SELECT
    topic.id, 'zh-TW', NULL, '五分鐘完成首次連線', '按最短路徑完成方案、轉發、用戶端安裝和連線驗證。', '<h2>你將完成什麼</h2>
<p>你會從"套餐已生效"走到"線路可用"：在控制台建一條轉發、裝上用戶端、連線，然後看到線路在執行。大約五分鐘，而且你不需要選擇任何連線型別 —— 所有線路都用 ultra，它做的就是在你打遊戲時把線路保持穩定。</p>

<h2>開始前準備</h2>
<ul>
  <li>一個使用中的套餐，控制台才會允許你建立轉發。</li>
  <li>想加速的遊戲或程式，以及它連線的位址（遊戲裡的伺服器列表通常能看到）。</li>
  <li>執行用戶端的裝置：Windows、macOS、Android 或 Linux 都可以，手機和電腦一樣能用。</li>
  <li>你的控制台帳號，以及大約五分鐘。</li>
</ul>

<h2>操作步驟</h2>
<pre class="mermaid">flowchart TD
accTitle: 從套餐到可用線路
accDescr: 先建立一條轉發，裝上用戶端並連線，再把遊戲指向本機連接埠。
A[開啟控制台] --&gt; B[新建一條轉發]
B --&gt; C[安裝用戶端]
C --&gt; D[登入並連線]
D --&gt; E[把遊戲指向本機連接埠]
E --&gt; F[開始遊戲]</pre>
<p>五步，下面每步都只有幾行說明。這裡沒有任何需要你判斷的技術選項。</p>
<figure class="kb-screenshot-figure">
  <img src="/assets/knowledgebase/screenshots/tenant/01-overview-zh-tw.png" width="1440" height="960" alt="控制台總覽頁，顯示使用中的套餐、轉發規則數量和三步開始使用">
  <figcaption>起點就在這裡：登入後的「總覽」頁會告訴你下一步該做什麼。</figcaption>
</figure>
<ol>
  <li><strong>開啟控制台，確認套餐在使用中。</strong>「總覽」頁的「最近的套餐」會顯示狀態；如果顯示已到期或已暫停，就沒法新建轉發。</li>
  <li><strong>新建一條轉發。</strong>進入「我的服務」，在套餐卡片上點「新建轉發」。選擇離遊戲伺服器最近的線路，填入遊戲連線的目標位址和目標連接埠，本機監聽連接埠留空讓系統分配，然後儲存。把這個本機連接埠記下來 —— 第 5 步要用。</li>
  <li><strong>安裝用戶端</strong>到你玩遊戲的裝置上：在「用戶端」頁面，Windows 點「下載安裝包」，Android 點「下載 APK」，Linux 複製那一條安裝命令執行。用控制台帳號登入。</li>
  <li><strong>連線。</strong>用戶端裡會列出你剛建的轉發，點連線，等到顯示 running。</li>
  <li><strong>把遊戲指向用戶端。</strong>遊戲裡凡是要填伺服器位址的地方，填 <code>127.0.0.1</code> 和第 2 步記下的本機連接埠，然後啟動遊戲。</li>
  <li><strong>開始遊戲，之後再回控制台看一眼。</strong>你玩的時候，那條轉發的「本期用量」會增加。</li>
</ol>

<h2>如何確認成功</h2>
<p>按順序看三個地方：用戶端裡線路顯示為 running；你遊戲時控制台上那條轉發的「本期用量」在增加；遊戲本身穩定，不會在一局中途突然卡頓。如果遊戲能進但用量一直是 0，幾乎都是本機連接埠填錯了 —— 回到第 5 步檢查。</p>

<h2>如果沒有成功</h2>
<p><strong>先停止</strong>反覆提交表單，先確認套餐狀態 —— 控制台不讓你儲存轉發，最常見的原因就是套餐到期。第二常見的是目標填錯：要填遊戲真正連線的位址，不是你下載遊戲的網站位址。第三是本機連接埠：遊戲必須使用系統分配的那個連接埠。其它情況（用戶端連不上、線路老是掉、遊戲進不去伺服器）都寫在下面的排查指南里。</p>

<h2>相關指南</h2>
<ul>
  <li><a href="/knowledgebase?thread=getting-started-first-forward-checklist">建立第一條轉發</a></li>
  <li><a href="/knowledgebase?thread=install-client">安裝用戶端</a></li>
  <li><a href="/knowledgebase?thread=connect-and-verify">連線並驗證</a></li>
</ul>
',
    'published', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM knowledgebase_topics AS topic
WHERE topic.topic_key = 'getting-started-five-minute-overview'
  AND NOT EXISTS (
      SELECT 1
      FROM knowledgebase_articles AS existing_article
      WHERE existing_article.topic_id = topic.id
        AND existing_article.locale = 'zh-TW'
  );

INSERT INTO knowledgebase_articles (
    topic_id, locale, author_user_id, title, excerpt, body_html,
    status, published_at, created_at, updated_at
)
SELECT
    topic.id, 'zh-TW', NULL, '安裝用戶端', '在 Windows、Android 或 Linux 上安裝並登入，然後確認用戶端在線。', '<p>在你玩遊戲的那臺裝置上裝一次用戶端，登入之後讓它一直開著就行。以後只要偶爾看一眼線路還在不在。</p>

<h2>你將完成什麼</h2>
<p>用戶端裝好、用控制台帳號登入，並且在控制台「用戶端」頁面的「線上裝置」裡能看到它。</p>

<h2>開始前準備</h2>
<ul>
<li>你的控制台郵箱和密碼。</li>
<li>你玩遊戲用的裝置。用戶端支援 Windows、macOS、Android 和 Linux。</li>
<li>手機或平板需要 Android 8.0 以上。</li>
<li>Linux 需要 Debian 11、12 或 13（x86_64 或 arm64 都可以），並且能用 root 執行命令。</li>
</ul>

<h2>操作步驟</h2>
<ol>
<li><strong>開啟「用戶端」頁面。</strong>需要的東西都在「下載與安裝」這一塊裡。</li>
<li><strong>安裝用戶端。</strong>Windows 點「下載安裝包」；Android 點「下載 APK」；macOS 點對應晶片的下載按鈕；Linux 點「複製命令」，再在目標機器上執行這條命令。</li>
<li><strong>登入。</strong>用控制台的郵箱和密碼。進去之後用戶端會顯示 <strong>Connection successful!</strong>。</li>
<li><strong>讓它一直開著。</strong>用戶端顯示 <strong>Connected</strong>，開關顯示 <strong>Running</strong>。</li>
</ol>
<pre class="mermaid">
flowchart TD
accTitle: 用戶端安裝路徑
accDescr: 按裝置系統選擇安裝方式，登入後確認用戶端線上
A[選擇裝置] --> B[作業系統]
B --> C[下載 Windows 安裝包]
B --> D[下載 Android APK]
B --> E[複製 Linux 安裝命令]
C --> F[登入]
D --> F
E --> F
F --> G[用戶端顯示 Connected 和 Running]
</pre>
<p>幾種裝置最後都停在同一處：用戶端已經登入，控制台的「線上裝置」裡能看到它。</p>
<figure><img src="/assets/knowledgebase/screenshots/tenant/03-client-install-zh-tw.png" width="1440" height="960" alt="用戶端頁面的下載與安裝區域，包括 Linux 安裝命令和 Windows 下載安裝包按鈕"><figcaption>下載入口和 Linux 安裝命令都在「用戶端」頁面的「下載與安裝」裡。</figcaption></figure>

<h2>如何確認成功</h2>
<p>看兩處。用戶端顯示 <strong>Connected</strong>；「用戶端」頁面底部的「線上裝置」裡出現這臺裝置（IP、系統和用戶端版本）。</p>

<h2>如果沒有成功</h2>
<p>如果一直停在 <strong>Connecting...</strong>，<strong>先停止</strong>，重新登入一次。按可能性排：密碼不對，或者帳號還沒生效；同時線上的裝置已經達到套餐上限（看頁面上的「最大線上」）；Android 把用戶端在後臺休眠了；Linux 命令沒有用 root 執行。</p>

<h2>相關指南</h2>
<p>接著做<a href="/knowledgebase?thread=connect-and-verify">連線並驗證</a>。裝好還沒有轉發，先看<a href="/knowledgebase?thread=getting-started-first-forward-checklist">建立第一條轉發</a>。有問題可以看<a href="/knowledgebase?thread=troubleshooting-common-mistakes">排查常見問題</a>；沒有螢幕的機器看<a href="/knowledgebase?thread=no-gui-clients">無介面執行用戶端</a>。</p>
',
    'published', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM knowledgebase_topics AS topic
WHERE topic.topic_key = 'install-client'
  AND NOT EXISTS (
      SELECT 1
      FROM knowledgebase_articles AS existing_article
      WHERE existing_article.topic_id = topic.id
        AND existing_article.locale = 'zh-TW'
  );

INSERT INTO knowledgebase_articles (
    topic_id, locale, author_user_id, title, excerpt, body_html,
    status, published_at, created_at, updated_at
)
SELECT
    topic.id, 'zh-TW', NULL, '建立第一條轉發', '選擇線路方案、填寫目標位址並記下系統分配的本機連接埠。', '<p>一條轉發就是你在控制台上畫的一條線：你的遊戲或服務在哪裡，以及本機用哪個連接埠去連它。</p>

<h2>你將完成什麼</h2>
<p>一條儲存好的轉發，加上一個分配給你的本機連接埠。下一篇指南要用這個連接埠，先記下來。</p>

<h2>開始前準備</h2>
<ul>
<li>一個使用中的套餐，控制台才允許儲存轉發。</li>
<li>你的遊戲或服務真正監聽的位址和連接埠。</li>
<li>它需要哪種協定：TCP、UDP 還是兩者都要。遊戲一般是 UDP。</li>
</ul>

<h2>操作步驟</h2>
<ol>
<li><strong>開啟「我的服務」，在套餐卡片上點「新建轉發」。</strong>在服務詳情頁裡點「新建轉發」也是同一個表單。</li>
<li><strong>選擇線路。</strong>挑離目標最近的那條線路。所有線路都用 ultra，這裡不需要再決定別的。</li>
<li><strong>填寫目標位址和目標連接埠。</strong>這裡要填遊戲自己連的位址，不是你下載遊戲的網站位址。</li>
<li><strong>選擇協定</strong>，也就是你的服務需要的傳輸方式：TCP、UDP 或 TCP + UDP。</li>
<li><strong>點「新建轉發」儲存。</strong>本機監聽連接埠留空就行，系統會自動分配，除非你有特別的理由要指定。</li>
<li><strong>記下分配給你的本機連接埠。</strong>儲存後會回到服務詳情頁，新轉發出現在「轉發規則」表裡，「本機連接埠」一列就是它。</li>
</ol>
<pre class="mermaid">
flowchart TD
accTitle: 第一條轉發的表單路徑
accDescr: 開啟使用中的套餐，選擇線路，填寫目標和協定，儲存後記下分配的本機連接埠
A[開啟我的服務] --> B[點新建轉發]
B --> C[選擇線路]
C --> D[填寫目標位址和目標連接埠]
D --> E[選擇協定]
E --> F[儲存]
F --> G[記下分配的本機連接埠]
</pre>
<p>表單只問線路、目標、協定和本機連接埠，其餘都替你填好了。</p>
<figure><img src="/assets/knowledgebase/screenshots/tenant/02-forward-form-zh-tw.png" width="1440" height="960" alt="新建轉發表單，包含線路、目標位址、目標連接埠、本機監聽連接埠和協定"><figcaption>「新建轉發」表單，真正要你決定的只有四個欄位。</figcaption></figure>

<h2>如何確認成功</h2>
<p>在「轉發規則」表裡，這一行的狀態是「執行中」，「本機連接埠」已經有數字，「節點狀態」不再一直顯示「正在分配節點」。接著去「用戶端」頁面，確認準備承載這條轉發的裝置出現在「線上裝置」裡。</p>

<h2>如果沒有成功</h2>
<p>控制台不讓儲存時，<strong>先停止</strong>，別反覆點。三個常見原因：套餐已經到期；目標位址或連接埠填錯；你填的本機連接埠已經被佔用——清空它，讓系統重新分配一個。如果「線路」下拉框是空的，說明你的套餐還沒有開放線路，請聯絡客服。</p>

<h2>相關指南</h2>
<p>接著做<a href="/knowledgebase?thread=connect-and-verify">連線並驗證</a>。用戶端還沒裝的話，先看<a href="/knowledgebase?thread=install-client">安裝用戶端</a>。</p>
',
    'published', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM knowledgebase_topics AS topic
WHERE topic.topic_key = 'getting-started-first-forward-checklist'
  AND NOT EXISTS (
      SELECT 1
      FROM knowledgebase_articles AS existing_article
      WHERE existing_article.topic_id = topic.id
        AND existing_article.locale = 'zh-TW'
  );

INSERT INTO knowledgebase_articles (
    topic_id, locale, author_user_id, title, excerpt, body_html,
    status, published_at, created_at, updated_at
)
SELECT
    topic.id, 'zh-TW', NULL, '連線並驗證', '選擇正確的用戶端位址，測試服務並確認轉發已經運作。', '<p>轉發已經儲存，用戶端也線上了。最後一步是告訴遊戲該連哪裡。</p>

<h2>你將完成什麼</h2>
<p>讓遊戲指向用戶端，並且確認流量真的在走。</p>

<h2>開始前準備</h2>
<ul>
<li>一條儲存好的轉發，以及系統分配的本機連接埠。</li>
<li>執行用戶端的那臺裝置上，用戶端已經裝好並登入。</li>
<li>遊戲開著的時候留幾分鐘做測試。</li>
</ul>

<h2>操作步驟</h2>
<ol>
<li><strong>先問一個問題：遊戲和用戶端在同一臺裝置上嗎？</strong>答案決定你要填哪個位址。</li>
<li><strong>同一臺裝置：</strong>用 <code>127.0.0.1</code> 加分配的本機連接埠。這是最常見的情況，而且只監聽迴環位址，區域網裡其他裝置碰不到它。</li>
<li><strong>不同裝置：</strong>用可訪問的 Windows 或 Linux 用戶端主機位址加同一個連接埠，並在兩臺裝置之間的防火牆、安全組或 NAT 上放行這個連接埠。</li>
<li><strong>啟動遊戲並連線。</strong>玩的時候看一眼那條轉發。</li>
</ol>
<pre class="mermaid">
flowchart TD
accTitle: 選擇用戶端連線位址
accDescr: 先確認遊戲在哪臺裝置上執行，再決定用迴環位址還是用戶端主機位址
A[遊戲在哪臺裝置上執行？] -- 與用戶端同機 --> B[使用 127.0.0.1 和本機連接埠]
A -- 另一臺裝置 --> C[使用用戶端主機位址]
B --> D[啟動遊戲]
C --> D
</pre>
<p>同一臺裝置時連接埠只留在迴環位址上；跨裝置時需要在中間的網路裡放行一條路徑。</p>
<figure><img src="/assets/knowledgebase/screenshots/tenant/04-forward-result-zh-tw.png" width="1440" height="960" alt="服務詳情頁的轉發規則表，顯示本機連接埠、目標、協定和本期用量"><figcaption>服務詳情頁的「轉發規則」裡能看到本機連接埠和它的本期用量。</figcaption></figure>

<h2>如何確認成功</h2>
<p>看兩處。用戶端顯示這條線路 running；你玩的時候，「轉發規則」裡這條轉發的「本期用量」在增加。Android 上用戶端就跑在手機裡，所以那邊的遊戲同樣用 <code>127.0.0.1</code>。</p>

<h2>如果沒有成功</h2>
<p><strong>先停止</strong>，先核對位址。按可能性排：連接埠和分配的本機連接埠不一致；跨裝置時被防火牆或 NAT 擋住了；目標服務本身沒起來——從目標服務自己的主機測試目標服務，再看目標日誌。測試時要測實際應用協定，不要只 ping。</p>

<h2>相關指南</h2>
<p>還是不通就看<a href="/knowledgebase?thread=troubleshooting-common-mistakes">排查常見問題</a>。忘了連接埠是多少，回<a href="/knowledgebase?thread=getting-started-first-forward-checklist">建立第一條轉發</a>看一眼。</p>
',
    'published', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM knowledgebase_topics AS topic
WHERE topic.topic_key = 'connect-and-verify'
  AND NOT EXISTS (
      SELECT 1
      FROM knowledgebase_articles AS existing_article
      WHERE existing_article.topic_id = topic.id
        AND existing_article.locale = 'zh-TW'
  );

INSERT INTO knowledgebase_articles (
    topic_id, locale, author_user_id, title, excerpt, body_html,
    status, published_at, created_at, updated_at
)
SELECT
    topic.id, 'zh-TW', NULL, '排查常見問題', '從看得見的症狀開始，按最短且安全的順序恢復連線。', '<p>遊戲連不上的時候，幾乎都是這三個原因之一。按順序查一遍，通常一兩分鐘就能回到遊戲裡。</p>

<h2>你將完成什麼</h2>
<p>找出這三個常見原因裡屬於你的那一個，修好它，重新進遊戲。</p>

<h2>開始前準備</h2>
<ul>
<li>開啟控制台，並且保證你玩遊戲那臺裝置上的用戶端在執行。</li>
<li>你之前跟著做的那篇指南，萬一要再查一次本機連接埠。</li>
</ul>

<h2>操作步驟</h2>
<ol>
<li><strong>用戶端線上嗎？</strong>「用戶端」頁面底部的「線上裝置」裡應該能看到這臺裝置。沒有的話，重啟用戶端，等幾秒再重新整理頁面；如果「最大線上」已經滿了，先在列表裡把不用的裝置「踢下線」。</li>
<li><strong>位址是否正確？</strong>和用戶端同一臺裝置就用 127.0.0.1 加本機連接埠；不同裝置就用用戶端主機位址。</li>
<li><strong>連接埠已放行嗎？</strong>只需要放行你真正用的那個連接埠，防火牆、安全組或 NAT 上放行一條就夠。</li>
<li><strong>轉發和線路正常嗎？</strong>在服務詳情頁的「轉發規則」裡，這一行的狀態應該是「執行中」，「節點狀態」不應該一直停在「正在分配節點」；從目標服務自己的主機測試目標服務時也應該有回應。</li>
<li><strong>還是不行？</strong>把轉發的編號、用的是哪臺裝置、出問題的大概時間一起發給客服。</li>
</ol>
<pre class="mermaid">
flowchart TD
accTitle: 從症狀開始排查
accDescr: 從遊戲連不上開始，依次檢查用戶端線上、位址，最後檢查目標服務
A[遊戲連不上] --> B[用戶端線上嗎？]
B -- 否 --> C[重啟用戶端再試]
B -- 是 --> D[位址和連接埠正確嗎？]
D -- 否 --> E[改用 127.0.0.1 或用戶端主機位址]
E --> C
D -- 是 --> F[從目標服務自己的主機測試目標服務]
F --> G[帶上轉發編號聯絡客服]
</pre>
<p>從頭到尾走一遍，別跳步。跳過的步驟通常最後還要回來補。</p>
<figure><img src="/assets/knowledgebase/screenshots/tenant/05-online-devices-zh-tw.png" width="1440" height="960" alt="用戶端頁面的線上裝置列表，顯示裝置 IP、系統和用戶端版本"><figcaption>「線上裝置」列出當前登入的每臺裝置；裝置不在這裡，轉發就不會通。</figcaption></figure>

<h2>如何確認成功</h2>
<p>用戶端顯示這條線路 running，你玩的時候「轉發規則」裡的「本期用量」又開始增加。</p>

<h2>如果沒有成功</h2>
<p>在改別的東西之前，<strong>先停止</strong>。不要隨手改一堆設定——那樣只會蓋住真正的原因，讓下一次排查更慢。上面四項都沒問題的話，直接聯絡客服，這是從這裡出發最快的路。</p>

<h2>相關指南</h2>
<p>回到<a href="/knowledgebase?thread=connect-and-verify">連線並驗證</a>；如果那一行本身看著就不對，看<a href="/knowledgebase?thread=getting-started-first-forward-checklist">建立第一條轉發</a>。</p>
',
    'published', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM knowledgebase_topics AS topic
WHERE topic.topic_key = 'troubleshooting-common-mistakes'
  AND NOT EXISTS (
      SELECT 1
      FROM knowledgebase_articles AS existing_article
      WHERE existing_article.topic_id = topic.id
        AND existing_article.locale = 'zh-TW'
  );

INSERT INTO knowledgebase_articles (
    topic_id, locale, author_user_id, title, excerpt, body_html,
    status, published_at, created_at, updated_at
)
SELECT
    topic.id, 'zh-TW', NULL, '名詞解釋', '用淺白語言解釋指南中出現的產品和網路術語。', '<p>其他指南里出現的詞，這裡每個用一句話說清楚。遇到看不懂的詞就開啟這一頁，看完再關掉。</p>

<h2>你將完成什麼</h2>
<p>指南里的每個詞你都能看懂，不需要讀任何技術資料。</p>

<h2>開始前準備</h2>
<ul>
<li>不用準備什麼。這一頁適合一直開在另一個標籤頁裡。</li>
<li>少了你要查的詞，告訴支援，我們會補上。</li>
</ul>

<h2>操作步驟</h2>
<ol>
<li><strong>在下面的表裡找到那個詞。</strong></li>
<li><strong>讀那一句話的解釋。</strong>看懂就可以停下了。</li>
<li><strong>想真正用起來，就去開啟旁邊對應的指南。</strong></li>
</ol>
<table>
<thead>
<tr><th>詞</th><th>意思</th></tr>
</thead>
<tbody>
<tr><td><strong>使用中的套餐</strong></td><td>你付過錢而且還在有效期內的套餐。只有套餐有效時轉發才會執行。</td></tr>
<tr><td><strong>轉發</strong></td><td>你在控制台上畫的一條線：一頭是目標，另一頭是本機連接埠。</td></tr>
<tr><td><strong>線路</strong></td><td>流量從哪條線路出去。挑離目標最近的那條。</td></tr>
<tr><td><strong>目標位址</strong></td><td>你要連線的遊戲或服務的位址。</td></tr>
<tr><td><strong>目標連接埠</strong></td><td>那個位址監聽的連接埠。</td></tr>
<tr><td><strong>本機監聽連接埠</strong></td><td>系統分給你自己裝置的連接埠，在「轉發規則」裡顯示為「本機連接埠」。遊戲用這個。</td></tr>
<tr><td><strong>協定</strong></td><td>流量是 TCP、UDP 還是兩者都要。遊戲一般是 UDP。</td></tr>
<tr><td><strong>用戶端</strong></td><td>在你裝置上承載這條線路的應用。</td></tr>
<tr><td><strong>Running</strong></td><td>線路通了以後用戶端顯示的狀態。</td></tr>
<tr><td><strong>線上裝置</strong></td><td>「用戶端」頁面上當前登入著的裝置列表。裝置在列表裡，說明用戶端正常。</td></tr>
<tr><td><strong>最大線上</strong></td><td>你的套餐允許同時登入的裝置數。滿了以後新裝置登入不上，可以把不用的裝置踢下線。</td></tr>
<tr><td><strong>ultra</strong></td><td>所有線路都用它承載。它讓你玩的時候線路保持穩定。</td></tr>
<tr><td><strong>應用驗證</strong></td><td>用遊戲本身去測，而不是隻 ping 一下。</td></tr>
</tbody>
</table>

<h2>如何確認成功</h2>
<p>你讀完一篇指南，中途沒有被任何詞卡住。</p>

<h2>如果沒有成功</h2>
<p>兩篇指南看起來說法不一致時，<strong>先停止</strong>，直接問支援。設定不要靠猜——猜錯花的時間比問一句多得多。</p>

<h2>相關指南</h2>
<p>先從<a href="/knowledgebase?thread=getting-started-five-minute-overview">五分鐘完成首次連線</a>開始，再把<a href="/knowledgebase?thread=troubleshooting-common-mistakes">排查常見問題</a>放在手邊。</p>
',
    'published', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM knowledgebase_topics AS topic
WHERE topic.topic_key = 'glossary'
  AND NOT EXISTS (
      SELECT 1
      FROM knowledgebase_articles AS existing_article
      WHERE existing_article.topic_id = topic.id
        AND existing_article.locale = 'zh-TW'
  );

INSERT INTO knowledgebase_articles (
    topic_id, locale, author_user_id, title, excerpt, body_html,
    status, published_at, created_at, updated_at
)
SELECT
    topic.id, 'zh-TW', NULL, '無介面執行用戶端', '在 Windows 或 macOS 上把無介面用戶端安裝為服務或守護程序，驗證並移除。', '<h2>你將完成什麼</h2>
<p>你會讓一臺電腦自己把線路跑起來 —— 一臺伺服器、一臺小主機，或者任何長期開機的機器。沒有人需要登入，桌面上不會出現視窗或圖示。裝一次、確認一次，之後用一條短命令管理它。</p>

<h2>開始前準備</h2>
<ul>
  <li>控制台「用戶端」頁面上的 <strong>用戶端 ID</strong> 和 <strong>用戶端令牌</strong>，它們就是這臺機器的登入憑據，請像密碼一樣保管。</li>
  <li>在這臺機器上安裝軟體的許可權（Windows 需要管理員，macOS 需要你自己的密碼）。</li>
  <li>兩分鐘時間。macOS 下載約 80 MB，Windows 約 34 MB。</li>
</ul>

<h2>操作步驟</h2>
<pre class="mermaid">flowchart TD
accTitle: 不用螢幕的電腦設定流程
accDescr: 選擇電腦，安裝後臺用戶端，然後在控制台確認線路已經在執行。
A[選擇電腦] --&gt; B{哪個系統}
B -- macOS --&gt; C[先裝應用，再執行一條命令]
B -- Windows --&gt; D[解壓下載包，再執行一條命令]
C --&gt; E[確認顯示 running]
D --&gt; E
E --&gt; F[在控制台看到裝置線上]</pre>
<p>整件事就是這張圖：選系統、執行一條命令、確認結果。之後的一切都在後臺進行。</p>
<figure class="kb-screenshot-figure">
  <img src="/assets/knowledgebase/screenshots/tenant/03-client-install-zh-tw.png" width="1440" height="960" alt="用戶端頁面，顯示下載入口以及這臺機器的用戶端 ID 與用戶端令牌">
  <figcaption>用戶端 ID 和用戶端令牌在同一頁，下載按你的系統選擇「無介面版」。</figcaption>
</figure>
<ol>
  <li><strong>挑選電腦。</strong>用一臺長期開機的機器，不需要顯示器、不需要鍵盤，也不需要有人登入。</li>
  <li><strong>先安裝應用。</strong>macOS：開啟下載的磁碟映像，把 <strong>LeiKwan Bridge</strong> 拖進 <strong>應用程式</strong>。Windows：把無介面版下載包解壓到一個資料夾。</li>
  <li><strong>執行一次安裝命令。</strong>macOS：開啟「終端」，貼上用戶端頁面「無介面版」裡「複製 macOS 命令」得到的命令。Windows：在解壓出來的資料夾上右鍵，以管理員身份開啟終端，貼上「複製 Windows 命令」得到的命令。兩條命令裡都已經帶了這臺機器的身份。</li>
  <li><strong>確認已經啟動。</strong>輸入 <code>leikwan status</code>，看到轉發顯示為 running 就對了。</li>
  <li><strong>回到控制台看一眼。</strong>這臺裝置會像裝了普通用戶端的電腦一樣出現在「線上裝置」裡。</li>
  <li><strong>以後只需要三條命令。</strong>檢視用 <code>leikwan status</code>，覺得卡住用 <code>leikwan restart</code>，升級用 <code>leikwan update</code>；如果只想看看有沒有新版本，用 <code>leikwan update --check</code>。</li>
  <li><strong>需要解除安裝時：</strong>執行同一個用戶端頁面上的解除安裝命令，或者先用 <code>leikwan stop</code> 暫時關掉它。</li>
</ol>

<h2>如何確認成功</h2>
<p>兩個簡單檢查。控制台「線上裝置」裡能看到這臺電腦，和其他裝置一樣；在電腦上執行 <code>leikwan status</code>，線路顯示為 running，並且命令以成功狀態結束，所以監控工具也可以直接用它。想看細節時，<code>leikwan logs -n 50</code> 顯示最近 50 行日誌，<code>leikwan doctor</code> 一次列清整體狀態。</p>

<h2>如果沒有成功</h2>
<p><strong>先停止把用戶端令牌發給任何人 —— 聊天、工單、截圖都不行。</strong>最常見的原因是用戶端令牌抄錯或已失效：控制台裡看不到這臺裝置線上，日誌裡會寫著登入被拒絕。回到用戶端頁面重新複製用戶端 ID 與用戶端令牌，再執行一次安裝命令即可。如果控制台顯示裝置線上、但某條轉發仍是停止狀態，等半分鐘 —— 轉發由控制台決定，電腦會在幾秒內跟上。仍然不通，就把 <code>leikwan doctor</code> 的輸出發給客服，那份內容不含任何機密。</p>

<h2>相關指南</h2>
<ul>
  <li><a href="/knowledgebase?thread=install-client">安裝用戶端</a></li>
  <li><a href="/knowledgebase?thread=connect-and-verify">連線與驗證</a></li>
  <li><a href="/knowledgebase?thread=troubleshooting-common-mistakes">常見問題排查</a></li>
</ul>
',
    'published', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM knowledgebase_topics AS topic
WHERE topic.topic_key = 'no-gui-clients'
  AND NOT EXISTS (
      SELECT 1
      FROM knowledgebase_articles AS existing_article
      WHERE existing_article.topic_id = topic.id
        AND existing_article.locale = 'zh-TW'
  );

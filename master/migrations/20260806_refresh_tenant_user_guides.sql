-- Guarded tenant guide refresh. Only untouched built-in bodies match the SHA-256 predicates below.

UPDATE knowledgebase_articles
SET title = 'Get Connected in Five Minutes',
    excerpt = 'Follow the shortest path from an active package to a verified connection.',
    body_html = '<h2>What you will accomplish</h2>
<p>You will go from an active package to one verified Forward without changing advanced settings. The goal is not merely to save a configuration. The goal is to send a real request through the assigned local port and receive the expected response.</p>

<h2>Before you begin</h2>
<ul>
  <li>Confirm that your package or service is marked <strong>Active</strong>.</li>
  <li>Know the destination host, destination port, and whether the application uses TCP, UDP, or both.</li>
  <li>Have a supported Windows, Android, or Linux device ready for the Client.</li>
  <li>Never share passwords, tokens, copied install commands, private keys, or route material.</li>
</ul>

<h2>Steps</h2>
<pre class="mermaid">flowchart TD
accTitle: Five-minute connection path
accDescr: Start with an active package, create one Forward, install and sign in to the Client, test the assigned local port, then either finish or open troubleshooting.
A[Active package] --&gt; B[Create one Forward]
B --&gt; C[Install and sign in]
C --&gt; D[Test assigned local port]
D --&gt; E{Works?}
E -- Yes --&gt; F[Setup complete]
E -- No --&gt; G[Open troubleshooting]</pre>
<p>The shortest safe path has one decision: if an application request succeeds through the local listener, setup is complete; if it fails, keep the saved Forward and troubleshoot the visible symptom.</p>
<figure class="kb-screenshot-figure">
  <img src="/assets/knowledgebase/screenshots/tenant/01-overview.png" width="1440" height="960" alt="Tenant overview showing the three setup steps and the Create a forward action">
  <figcaption>Demo screenshot. The package, counts, and IDs shown here are demonstration values.</figcaption>
</figure>
<ol>
  <li>Open the active service in the tenant panel.</li>
  <li>Select <strong>Create Forward</strong>.</li>
  <li>Choose the offered route package and enter the destination.</li>
  <li>Save the Forward and record its assigned local port.</li>
  <li>Install the Client and sign in.</li>
  <li>Wait for a recent client sync.</li>
  <li>Point your application at the local listener.</li>
  <li>Test with the actual application protocol, such as an HTTP request, SSH connection, or game connection.</li>
</ol>

<h2>How to know it worked</h2>
<p>The panel shows an active endpoint after provisioning, the Client shows a recent sync, and the Forward reaches <strong>Connected</strong> or <strong>Running</strong>. Those signals are different from final proof. Final proof is that the application request succeeds and returns the expected result. If you control the target, its logs should also show the request arriving.</p>

<h2>If it did not work</h2>
<p><strong>Stop before changing route details at random.</strong> Keep the first Forward, note the exact error and time, then open the troubleshooting guide. A saved configuration, completed provisioning, recent sync, running process, and successful application request are separate checkpoints.</p>

<h2>Related guides</h2>
<ul>
  <li><a href="/knowledgebase?thread=getting-started-first-forward-checklist">Create Your First Forward</a></li>
  <li><a href="/knowledgebase?thread=install-client">Install the Client</a></li>
  <li><a href="/knowledgebase?thread=connect-and-verify">Connect and Verify</a></li>
  <li><a href="/knowledgebase?thread=troubleshooting-common-mistakes">Troubleshoot Common Problems</a></li>
</ul>
',
    status = 'published',
    published_at = COALESCE(published_at, CURRENT_TIMESTAMP),
    updated_at = CURRENT_TIMESTAMP
WHERE topic_id IN (SELECT id FROM knowledgebase_topics WHERE topic_key = 'getting-started-five-minute-overview')
  AND locale = 'en'
  AND SHA2(body_html, 256) = '5a9d1cf71a3f498b422e861112411cc3a06228df340b8cd0183979e910af626d';

UPDATE knowledgebase_articles
SET title = 'Install the Client',
    excerpt = 'Install and sign in on Windows, Android, or Linux, then confirm the client is online.',
    body_html = '<h2>What you will accomplish</h2>
<p>You will install the Client on Windows, Android, or Linux, sign in safely, and confirm that the device has received a recent configuration sync.</p>

<h2>Before you begin</h2>
<ul>
  <li>Have your panel <strong>Master URL</strong>, account <strong>Email</strong>, and <strong>Password</strong> ready for Windows.</li>
  <li>For Android, use an arm64 device running Android 8.0+ and be ready to approve the VPN request.</li>
  <li>For Linux, use a machine with systemd, Python 3, network access, and root-equivalent permission.</li>
  <li>Treat credentials and copied commands as secrets. A Linux installer command contains a bearer agent token.</li>
</ul>

<h2>Steps</h2>
<pre class="mermaid">flowchart TD
accTitle: Client installation choices
accDescr: Choose the device operating system, follow its installation path, and finish by confirming a recent sync.
A[Choose device] --&gt; B{Operating system}
B -- Windows --&gt; C[Download Windows App]
B -- Android --&gt; D[Install arm64 Android app]
B -- Linux --&gt; E[Copy Linux install command]
C --&gt; F[Confirm recent sync]
D --&gt; F
E --&gt; F</pre>
<p>Each operating system has a different sign-in or installation path, but all three finish at the same observable result: the panel reports a recent client sync.</p>
<figure class="kb-screenshot-figure">
  <img src="/assets/knowledgebase/screenshots/tenant/03-client-install.png" width="1440" height="960" alt="Clients Install view showing Windows, Android, and Linux installation choices">
  <figcaption>Demo screenshot. The download buttons and sync time show where to check your own session.</figcaption>
</figure>
<ol>
  <li><strong>Windows:</strong> Open <strong>Tenant → Clients</strong> and select <strong>Download Windows App</strong>.</li>
  <li>Install and open the app.</li>
  <li>Enter <strong>Master URL</strong>, <strong>Email</strong>, and <strong>Password</strong>.</li>
  <li>Select <strong>Connect</strong>.</li>
  <li>Confirm <strong>Connection successful!</strong>, the global state <strong>Connected</strong>, and each ready Forward marked <strong>Running</strong>.</li>
  <li><strong>Android:</strong> Download and install the arm64 Android app on Android 8.0+.</li>
  <li>Sign in and approve the Android VPN request.</li>
  <li>Select one of the supported LeiGod services offered by the app. Use only a service that the app lets you select.</li>
  <li>Watch the visible state move from <strong>Ready to connect</strong> to <strong>Connecting...</strong> and then <strong>Connected</strong>.</li>
  <li><strong>Linux:</strong> Open <strong>Tenant → Clients</strong>, expand <strong>Linux Install</strong>, and select <strong>Copy Install Command</strong>.</li>
  <li>Run the copied command once with root-equivalent permission. Do not paste it into chat, tickets, logs, or shared shell history.</li>
  <li>Confirm the installer prints <code>installed leikwan-client-agent</code>.</li>
  <li>Return to the panel and confirm a recent sync. On the Linux host, you may also confirm that <code>config_pull_state.json</code> reports <code>status</code> as <code>ok</code>.</li>
</ol>

<h2>How to know it worked</h2>
<p>The panel shows a recent sync from the expected device. Windows also shows <strong>Connection successful!</strong>, global <strong>Connected</strong>, and a ready Forward as <strong>Running</strong>. Android reaches <strong>Connected</strong>. Linux reports the completed installer line and a successful pull state.</p>

<h2>If it did not work</h2>
<p><strong>Stop before sharing credentials or rerunning secret commands in public places.</strong> Check the Master URL, device clock, internet access, required permissions, and the exact visible error. On Android, confirm the VPN approval was granted. On Linux, confirm systemd and Python 3 are available, then collect diagnostics without copying the bearer agent token.</p>

<h2>Related guides</h2>
<ul>
  <li><a href="/knowledgebase?thread=getting-started-five-minute-overview">Get Connected in Five Minutes</a></li>
  <li><a href="/knowledgebase?thread=connect-and-verify">Connect and Verify</a></li>
  <li><a href="/knowledgebase?thread=troubleshooting-common-mistakes">Troubleshoot Common Problems</a></li>
</ul>
',
    status = 'published',
    published_at = COALESCE(published_at, CURRENT_TIMESTAMP),
    updated_at = CURRENT_TIMESTAMP
WHERE topic_id IN (SELECT id FROM knowledgebase_topics WHERE topic_key = 'install-client')
  AND locale = 'en'
  AND SHA2(body_html, 256) = 'fb08344920254cfcda2838d7529cc909a26d1f70692bdc08517d6f14a5ed1204';

UPDATE knowledgebase_articles
SET title = 'Create Your First Forward',
    excerpt = 'Choose a route package, enter the destination, save, and identify the assigned local port.',
    body_html = '<h2>What you will accomplish</h2>
<p>You will create one Forward from an active package, save only the visible destination settings, and record the assigned local port that your application will use.</p>

<h2>Before you begin</h2>
<ul>
  <li>Confirm that the package or service is <strong>Active</strong>.</li>
  <li>Know the destination hostname or IP address.</li>
  <li>Know the destination port.</li>
  <li>Know whether the application needs TCP, UDP, or both.</li>
</ul>

<h2>Steps</h2>
<pre class="mermaid">flowchart TD
accTitle: First Forward form path
accDescr: Open an active package, choose a route, enter the destination and transport, save, and record the local port when the Forward is ready.
A[Open active package] --&gt; B[Choose Forward Route]
B --&gt; C[Enter Target Host and Target Port]
C --&gt; D[Choose Transport]
D --&gt; E[Save]
E --&gt; F[Forward ready]
F --&gt; G[Record assigned local port]</pre>
<p>The form asks only for the route package, destination, local port preference, and transport. Saving starts setup; the assigned local port is the value you need for the next guide.</p>
<figure class="kb-screenshot-figure">
  <img src="/assets/knowledgebase/screenshots/tenant/02-forward-form.png" width="1440" height="960" alt="Create Forward form showing Forward Route, Target Host, Target Port, Local Listen Port, and Transport">
  <figcaption>Demo screenshot. Leave Local Listen Port empty when the panel should allocate it.</figcaption>
</figure>
<ol>
  <li>Open the active service and select <strong>Create Forward</strong>.</li>
  <li>Choose the offered package under <strong>Forward Route</strong>.</li>
  <li>Enter the destination in <strong>Target Host</strong>.</li>
  <li>Enter the service port in <strong>Target Port</strong>.</li>
  <li>Leave <strong>Local Listen Port</strong> blank to accept an assigned local port, unless you were given a specific safe port.</li>
  <li>Under <strong>Transport</strong>, choose <strong>TCP</strong> for TCP-only applications.</li>
  <li>Choose <strong>TCP + UDP</strong> when the application needs both.</li>
  <li>Choose <strong>UDP</strong> only for a UDP-only application.</li>
  <li>Select <strong>Create Forward</strong> to Save.</li>
  <li>Wait for provisioning to finish, then record the assigned local port shown on the Forward card.</li>
</ol>

<h2>How to know it worked</h2>
<p>The new Forward appears with the destination and assigned local port you expect. A saved record is only the first checkpoint. Wait for active setup, then a recent client sync, and later confirm <strong>Running</strong> plus a successful application request.</p>

<h2>If it did not work</h2>
<p><strong>Stop if no Forward Route is offered or the destination is uncertain.</strong> Do not guess at hidden settings. Check that the package is active, correct only the visible fields above, and ask your administrator to publish an appropriate route package when none is available.</p>

<h2>Related guides</h2>
<ul>
  <li><a href="/knowledgebase?thread=install-client">Install the Client</a></li>
  <li><a href="/knowledgebase?thread=connect-and-verify">Connect and Verify</a></li>
  <li><a href="/knowledgebase?thread=glossary">Glossary</a></li>
</ul>
',
    status = 'published',
    published_at = COALESCE(published_at, CURRENT_TIMESTAMP),
    updated_at = CURRENT_TIMESTAMP
WHERE topic_id IN (SELECT id FROM knowledgebase_topics WHERE topic_key = 'getting-started-first-forward-checklist')
  AND locale = 'en'
  AND SHA2(body_html, 256) = '4ac31556c0d61cf6ffa959bf99b9e166e9a9bf1232d2d3bdfc31a724cf1582d4';

UPDATE knowledgebase_articles
SET title = 'Connect and Verify',
    excerpt = 'Choose the correct client address, test the service, and confirm that the route is working.',
    body_html = '<h2>What you will accomplish</h2>
<p>You will choose the address that matches where your application runs, send a request through the Forward, and separate a panel status from proof that the target application received traffic.</p>

<h2>Before you begin</h2>
<ul>
  <li>Record the Forward''s local port.</li>
  <li>Confirm that the Client has a recent sync and the Forward shows <strong>Running</strong>.</li>
  <li>Know whether the application runs on the Client device or on another device.</li>
  <li>Use a protocol-aware test. A TCP port check alone does not prove an HTTP, SSH, database, or game request works correctly.</li>
</ul>

<h2>Steps</h2>
<pre class="mermaid">flowchart TD
accTitle: Choose the client connection address
accDescr: Use loopback on the same Client device, use a reachable Windows or Linux client-host address for another device with network controls as needed, and keep Android loopback-only.
A[Locate the application] --&gt; B{Same Client device?}
B -- Yes --&gt; C[Use 127.0.0.1 and local port]
B -- No --&gt; D{Client platform}
D -- Windows or Linux --&gt; E[Use reachable client-host address and port]
E --&gt; F[Allow firewall security group or NAT as needed]
D -- Android --&gt; G[Keep listener loopback-only]
C --&gt; H[Test application protocol]
F --&gt; H
G --&gt; H</pre>
<p>The deciding fact is where the application runs. Same-device traffic uses loopback. Cross-device traffic is possible only through a reachable Windows or Linux Client host and may need network access rules; Android remains loopback-only unless it is separately exposed by another supported setup.</p>
<figure class="kb-screenshot-figure">
  <img src="/assets/knowledgebase/screenshots/tenant/04-forward-result.png" width="1440" height="960" alt="Forward result showing the local listen address, target, transport, and Running status">
  <figcaption>Demo screenshot. Use the local listen value from your own Forward card, not the demonstration value.</figcaption>
</figure>
<ol>
  <li>If the application is on the Client device, enter <code>127.0.0.1</code> and the Forward''s local port.</li>
  <li>If another device uses a Windows or Linux Client, enter a reachable Windows or Linux client-host address and the same local port.</li>
  <li>For cross-device access, allow that port in the host firewall.</li>
  <li>If applicable, allow it in the cloud security group.</li>
  <li>If the Client is behind a router, configure NAT only when your network owner approves it.</li>
  <li>Do not present Android as a cross-device listener. Its listener stays loopback-only unless separately exposed through an approved design.</li>
  <li>Send a request with the actual application protocol.</li>
  <li>Check the response and, when available, confirm receipt in the target logs.</li>
</ol>

<h2>How to know it worked</h2>
<p>Your application receives the expected response through the Client address and local port. The strongest confirmation is a matching request in the target logs. <strong>Connected</strong>, <strong>Running</strong>, and an open local port are useful checkpoints, but none alone proves the destination application handled the request.</p>

<h2>If it did not work</h2>
<p><strong>Stop before opening broad firewall ranges or assuming a public IP always works.</strong> Recheck same-device versus cross-device addressing, confirm the exact local port, and test the destination service from its own host. Then use diagnostics or ask support with the error, time, Client platform, and Forward name, but no secrets.</p>

<h2>Related guides</h2>
<ul>
  <li><a href="/knowledgebase?thread=install-client">Install the Client</a></li>
  <li><a href="/knowledgebase?thread=troubleshooting-common-mistakes">Troubleshoot Common Problems</a></li>
  <li><a href="/knowledgebase?thread=glossary">Glossary</a></li>
</ul>
',
    status = 'published',
    published_at = COALESCE(published_at, CURRENT_TIMESTAMP),
    updated_at = CURRENT_TIMESTAMP
WHERE topic_id IN (SELECT id FROM knowledgebase_topics WHERE topic_key = 'connect-and-verify')
  AND locale = 'en'
  AND SHA2(body_html, 256) = '53b4d27e69c5f7acf1efec30bc0339aceece3b951f3ff64b33843bdd2241a48e';

UPDATE knowledgebase_articles
SET title = 'Choose a Connection Mode',
    excerpt = 'Understand Single, Race, and Bond by the outcome you need rather than internal topology.',
    body_html = '<h2>What you will accomplish</h2>
<p>You will choose among Single, Race, and Bond by the result you need, while avoiding promises that the selected mode or platform cannot guarantee.</p>

<h2>Before you begin</h2>
<ul>
  <li>Start with a working basic Forward when possible.</li>
  <li>Read the published route contract for your package and Client platform.</li>
  <li>Identify whether you need simplicity, path redundancy, or an explicitly supported Bond design.</li>
  <li>Do not choose a mode only because its name sounds faster.</li>
</ul>

<h2>Steps</h2>
<pre class="mermaid">flowchart TD
accTitle: Connection mode decision
accDescr: Choose Single for the simplest stable path, Race for supported path redundancy without aggregation, and Bond only when the plan explicitly supports it and its requirements are verified.
A[What outcome do you need?] --&gt; B{Simplest stable path?}
B -- Yes --&gt; C[Choose Single]
B -- No --&gt; D{Redundancy without aggregation?}
D -- Yes --&gt; E[Choose Race]
D -- No --&gt; F{Plan explicitly supports Bond?}
F -- Yes --&gt; G[Choose Bond and verify requirements]
F -- No --&gt; H[Ask your administrator]</pre>
<p>The safe default is Single. Race is for supported path selection or redundancy, not combined bandwidth. Bond is an advanced choice only when the published route contract says the package and platform support it.</p>
<figure class="kb-screenshot-figure">
  <img src="/assets/knowledgebase/screenshots/tenant/04-forward-result.png" width="1440" height="960" alt="Forward result showing the user-facing route result instead of internal Single, Race, or Bond wiring">
  <figcaption>Demo screenshot. The tenant checks the resulting route and status; internal topology stays managed by the plan.</figcaption>
</figure>
<ol>
  <li>Choose <strong>Single</strong> for the simplest stable setup.</li>
  <li>Choose <strong>Race</strong> when the contract offers multiple usable paths and you want redundancy without aggregation.</li>
  <li>Remember that Race is not bandwidth aggregation.</li>
  <li>On Linux TCP Race, expect one winner per connection.</li>
  <li>On Linux UDP Race, the Client may fan out datagrams across usable paths.</li>
  <li>Use Race only with at least two usable endpoints. Exact behavior differs by platform.</li>
  <li>Choose <strong>Bond</strong> only when the published route contract explicitly supports it.</li>
  <li>Verify every listed Bond requirement before testing. Not every Bond route requires a Sidecar.</li>
  <li>If support is unclear, ask your administrator instead of guessing.</li>
</ol>

<h2>How to know it worked</h2>
<p>The selected mode is allowed by the package, supported by the Client platform, and the application succeeds under the intended conditions. For Race, test that supported requests continue when one usable path is unavailable. For Bond, verify the published requirements and actual application result rather than inferring performance from status alone.</p>

<h2>If it did not work</h2>
<p><strong>Stop before promising speed doubling, full configured bandwidth, or use of every path.</strong> Return to Single if it is available and stable, record the failing platform and route package, then ask your administrator whether the contract supports the desired Race or Bond behavior.</p>

<h2>Related guides</h2>
<ul>
  <li><a href="/knowledgebase?thread=bond-sidecar-setup">Configure a Sidecar</a></li>
  <li><a href="/knowledgebase?thread=troubleshooting-common-mistakes">Troubleshoot Common Problems</a></li>
  <li><a href="/knowledgebase?thread=glossary">Glossary</a></li>
</ul>
',
    status = 'published',
    published_at = COALESCE(published_at, CURRENT_TIMESTAMP),
    updated_at = CURRENT_TIMESTAMP
WHERE topic_id IN (SELECT id FROM knowledgebase_topics WHERE topic_key = 'connection-modes-single-race-bond')
  AND locale = 'en'
  AND SHA2(body_html, 256) = 'ac4ec7d678824ac8c31534849c2a0b9a886bbd1c50b8ebdcb56877359d04946e';

UPDATE knowledgebase_articles
SET title = 'Configure a Sidecar',
    excerpt = 'Create and verify a Sidecar only when your Bond route package requires one.',
    body_html = '<h2>What you will accomplish</h2>
<p>You will create and verify a Sidecar only when a Bond package''s published route contract requires one. A Sidecar is intermediate target-side transport. It is not the target application.</p>

<h2>Before you begin</h2>
<ul>
  <li>Confirm that the selected Bond package explicitly requires a Sidecar.</li>
  <li>Have a target-side machine that can reach the backend service.</li>
  <li>Know the approved <strong>Sidecar Host</strong> and Bond transport port.</li>
  <li>Have root-equivalent permission on the Sidecar machine.</li>
  <li>Keep the panel-provided install command and all credentials secret.</li>
</ul>

<h2>Steps</h2>
<pre class="mermaid">flowchart LR
accTitle: Bond route with a Sidecar
accDescr: The Client reaches the target service through a managed Slave path and an intermediate Sidecar.
A[Client] --&gt; B[Managed Slave path]
B --&gt; C[Sidecar]
C --&gt; D[Target service]</pre>
<p>The Sidecar sits between the managed path and the target service. It must be online and able to reach the backend, but users still verify the target application separately.</p>
<figure class="kb-screenshot-figure">
  <img src="/assets/knowledgebase/screenshots/tenant/06-target-endpoint.png" width="1440" height="960" alt="Target Endpoints form showing the host and all three listen port fields">
  <figcaption>Demo screenshot. Only create a tenant-owned endpoint when the published route contract requires it.</figcaption>
</figure>
<ol>
  <li>Open <strong>Target Endpoints</strong> under <strong>Advanced</strong>.</li>
  <li>Select the <strong>Create</strong> tab.</li>
  <li>In <strong>Create Transport Endpoint</strong>, enter a recognizable <strong>Name</strong>.</li>
  <li>Enter the approved <strong>Sidecar Host</strong>.</li>
  <li>Enter the approved Bond transport port in <strong>Transport Listen Port</strong>.</li>
  <li>Select <strong>Save Transport Endpoint</strong>.</li>
  <li>On its card, open <strong>Install Command</strong> and copy it without exposing its contents.</li>
  <li>Run it on the Sidecar machine with root-equivalent permission.</li>
  <li>Return to the panel and wait for <strong>Last Heartbeat</strong>, <strong>Agent Version</strong>, and <strong>Last Status</strong> to update.</li>
  <li>From the Sidecar machine, test the backend listener at the configured target host and port.</li>
  <li>Create or refresh the Bond Forward as directed by the published route contract.</li>
</ol>

<h2>How to know it worked</h2>
<p>The Sidecar reports a recent <strong>Last Heartbeat</strong>, the expected agent status, and the Bond Forward can carry a real application request to the target. A heartbeat alone does not prove backend health. Separately test the backend listener and, if possible, confirm the request in target logs.</p>

<h2>If it did not work</h2>
<p><strong>Stop before changing ports, addresses, or route settings at random.</strong> If heartbeat is missing, check the Sidecar service and outbound access. If heartbeat is current but the application fails, test the backend from the Sidecar host. Share diagnostics with support, but never share the generated install command, tokens, passwords, route keys, private keys, or internal line or session material.</p>

<h2>Related guides</h2>
<ul>
  <li><a href="/knowledgebase?thread=connection-modes-single-race-bond">Choose a Connection Mode</a></li>
  <li><a href="/knowledgebase?thread=connect-and-verify">Connect and Verify</a></li>
  <li><a href="/knowledgebase?thread=troubleshooting-common-mistakes">Troubleshoot Common Problems</a></li>
</ul>
',
    status = 'published',
    published_at = COALESCE(published_at, CURRENT_TIMESTAMP),
    updated_at = CURRENT_TIMESTAMP
WHERE topic_id IN (SELECT id FROM knowledgebase_topics WHERE topic_key = 'bond-sidecar-setup')
  AND locale = 'en'
  AND SHA2(body_html, 256) = '952b0ac65df14b6d954f106a067e80a09b5f3d6c412934b87f826b5627af4132';

UPDATE knowledgebase_articles
SET title = 'Troubleshoot Common Problems',
    excerpt = 'Start from the symptom you can see and follow the shortest safe recovery path.',
    body_html = '<h2>What you will accomplish</h2>
<p>You will start with the symptom you can see, check one boundary at a time, and stop before broad or random changes make the original problem harder to identify.</p>

<h2>Before you begin</h2>
<ul>
  <li>Write down the exact error, time, Client platform, Forward name, and local port.</li>
  <li>Keep the saved Forward unchanged while you check its current state.</li>
  <li>Do not include passwords, tokens, generated commands, route keys, private keys, or internal session material in screenshots or support messages.</li>
  <li>Use the real application protocol for the final test.</li>
</ul>

<h2>Steps</h2>
<pre class="mermaid">flowchart TD
accTitle: Symptom-first troubleshooting
accDescr: After a failed request, check recent sync, the address, network access to the port, Forward and route health, and the target service before using diagnostics and support.
A[Application request failed] --&gt; B{Recent sync?}
B -- No --&gt; C[Restore Client sync]
B -- Yes --&gt; D{Correct address?}
D -- No --&gt; E[Use loopback or reachable client host]
D -- Yes --&gt; F{Port allowed?}
F -- No --&gt; G[Allow only the required port]
F -- Yes --&gt; H{Forward and route healthy?}
H -- No --&gt; I[Open View Diagnostics]
H -- Yes --&gt; J[Test target from its own host]
C --&gt; K[Retry application]
E --&gt; K
G --&gt; K
I --&gt; L[Diagnostics and support]
J --&gt; L</pre>
<p>The checks move from the Client toward the target. Fix the first failed boundary, retry once, and preserve evidence instead of making random topology changes.</p>
<figure class="kb-screenshot-figure">
  <img src="/assets/knowledgebase/screenshots/tenant/05-diagnostics.png" width="1440" height="960" alt="Client diagnostics view showing Run Diagnostics before View Diagnostics and a healthy result">
  <figcaption>Demo screenshot. Run a fresh check first, then open View Diagnostics for the details.</figcaption>
</figure>
<ol>
  <li>Confirm a recent sync for the expected Client.</li>
  <li>Confirm the Forward shows <strong>Running</strong>.</li>
  <li>Check the correct address: use <code>127.0.0.1</code> on the same Client device.</li>
  <li>For another device, use a reachable Windows or Linux Client-host address. Do not treat Android as a cross-device listener.</li>
  <li>Confirm the local port matches the Forward card.</li>
  <li>For cross-device use, confirm that only the required port is allowed by the firewall, security group, and NAT as applicable.</li>
  <li>Check that the forward and route are healthy in <strong>View Diagnostics</strong>.</li>
  <li>Test the target service from its own host and port.</li>
  <li>Retry with the actual application protocol.</li>
  <li>If it still fails, collect diagnostics and contact support with the symptom and time.</li>
</ol>

<h2>How to know it worked</h2>
<p>The first failing boundary becomes healthy and the real application request succeeds. A recent sync proves configuration delivery, <strong>Running</strong> proves a local process state, and a successful target response proves the complete request path.</p>

<h2>If it did not work</h2>
<p><strong>Stop before reinstalling everything, opening broad port ranges, or making random topology changes.</strong> Preserve the error and diagnostics. Contact support with the Forward name, Client platform, test time, address type, local port, and sanitized results. Never send secrets.</p>

<h2>Related guides</h2>
<ul>
  <li><a href="/knowledgebase?thread=install-client">Install the Client</a></li>
  <li><a href="/knowledgebase?thread=connect-and-verify">Connect and Verify</a></li>
  <li><a href="/knowledgebase?thread=glossary">Glossary</a></li>
</ul>
',
    status = 'published',
    published_at = COALESCE(published_at, CURRENT_TIMESTAMP),
    updated_at = CURRENT_TIMESTAMP
WHERE topic_id IN (SELECT id FROM knowledgebase_topics WHERE topic_key = 'troubleshooting-common-mistakes')
  AND locale = 'en'
  AND SHA2(body_html, 256) = '335fcc4fc84bb6f26a2353e6615a73033901f520a81dc88149151e6a9439f02a';

UPDATE knowledgebase_articles
SET title = '五分钟完成首次连接',
    excerpt = '按最短路径完成套餐、转发、客户端安装和连接验证。',
    body_html = '<h2>你将完成什么</h2>
<p>你将从已生效的套餐或服务出发，创建一条转发，安装并登录客户端，再用真实应用协议完成首次连接验证。保存配置只是起点，最终目标是应用收到预期响应。</p>

<h2>开始前准备</h2>
<ul>
  <li>确认套餐或服务状态为<strong>有效</strong>。</li>
  <li>准备目标主机、目标端口，以及应用所需的 TCP、UDP 或两者。</li>
  <li>准备一台支持的 Windows、Android 或 Linux 设备。</li>
  <li>不要向任何人展示密码、令牌、复制的安装命令、密钥、会话或线路材料。</li>
</ul>

<h2>操作步骤</h2>
<pre class="mermaid">flowchart TD
accTitle: 五分钟首次连接路径
accDescr: 从有效套餐开始，创建一条转发，安装并登录客户端，测试系统分配的本地端口，成功后结束，失败时进入问题排查。
A[有效套餐] --&gt; B[创建一条转发]
B --&gt; C[安装并登录客户端]
C --&gt; D[测试分配的本地端口]
D --&gt; E{连接成功吗}
E -- 是 --&gt; F[首次设置完成]
E -- 否 --&gt; G[开始排查问题]</pre>
<p>最短且安全的路径只有一次判断：真实应用请求通过本地监听端口并获得正确结果，就算完成；否则先保留现有转发，再从可见症状着手排查。</p>
<figure class="kb-screenshot-figure">
  <img src="/assets/knowledgebase/screenshots/tenant/01-overview.png" width="1440" height="960" alt="租户概览页显示三步设置流程和 Create a forward 操作">
  <figcaption>演示截图：套餐、数量和 ID 都是演示值，请以你自己的面板为准。</figcaption>
</figure>
<ol>
  <li>在租户面板中打开已生效的服务。</li>
  <li>选择 <strong>Create Forward</strong>。</li>
  <li>选择系统提供的线路方案，并填写目标地址和端口。</li>
  <li>保存转发，记下系统分配的本地端口。</li>
  <li>安装客户端并登录。</li>
  <li>等待客户端出现最近同步记录。</li>
  <li>让应用连接本地监听地址和端口。</li>
  <li>使用实际应用协议验证，例如发出 HTTP 请求、建立 SSH 连接或启动游戏连接。</li>
</ol>

<h2>如何确认成功</h2>
<p>保存后已完成配置记录，预配完成后端点可用，最近同步表示配置已送达，<strong>Connected</strong> 或 <strong>Running</strong> 表示运行状态正常；这些检查点彼此独立。最终证据仍是应用请求成功，并在可查看时由目标日志确认请求到达。</p>

<h2>如果没有成功</h2>
<p><strong>先停止随意更改线路或端口。</strong>保留第一条转发，记录错误内容和发生时间，再按排查指南逐项检查。不要把保存、预配、最近同步、运行连接和应用成功混为一谈。</p>

<h2>相关指南</h2>
<ul>
  <li><a href="/knowledgebase?thread=getting-started-first-forward-checklist">创建第一条转发</a></li>
  <li><a href="/knowledgebase?thread=install-client">安装客户端</a></li>
  <li><a href="/knowledgebase?thread=connect-and-verify">连接并验证</a></li>
  <li><a href="/knowledgebase?thread=troubleshooting-common-mistakes">排查常见问题</a></li>
</ul>
',
    status = 'published',
    published_at = COALESCE(published_at, CURRENT_TIMESTAMP),
    updated_at = CURRENT_TIMESTAMP
WHERE topic_id IN (SELECT id FROM knowledgebase_topics WHERE topic_key = 'getting-started-five-minute-overview')
  AND locale = 'zh-Hans'
  AND SHA2(body_html, 256) = 'e90df395261a131b49e5e401fef61ad1896617f0faeba5bd8c28327b8e0eb933';

UPDATE knowledgebase_articles
SET title = '安装客户端',
    excerpt = '在 Windows、Android 或 Linux 上安装并登录，然后确认客户端在线。',
    body_html = '<h2>你将完成什么</h2>
<p>你将在 Windows、Android 或 Linux 上安装并登录客户端，随后确认设备在线且已经收到最近的转发配置。</p>

<h2>开始前准备</h2>
<ul>
  <li>Windows 登录需要面板提供的 <strong>Master URL</strong>、<strong>Email</strong> 和 <strong>Password</strong>。</li>
  <li>Android 需要 arm64、Android 8.0+ 设备，并允许系统建立 VPN 连接。</li>
  <li>Linux 主机需要 systemd、Python 3、网络访问能力和相当于 root 的权限。</li>
  <li>登录信息和复制的命令都属于秘密；Linux 安装命令包含可直接用于代理身份验证的令牌。</li>
</ul>

<h2>操作步骤</h2>
<pre class="mermaid">flowchart TD
accTitle: 客户端安装路径
accDescr: 按设备系统选择 Windows、Android 或 Linux 安装流程，完成后都要确认客户端最近同步。
A[选择设备] --&gt; B{操作系统}
B -- Windows --&gt; C[下载 Windows 应用]
B -- Android --&gt; D[安装 arm64 Android 应用]
B -- Linux --&gt; E[复制 Linux 安装命令]
C --&gt; F[确认最近同步]
D --&gt; F
E --&gt; F</pre>
<p>三个平台的安装入口和状态名称不同，但结束条件一致：目标设备上的客户端已连接，并且面板显示最近同步。</p>
<figure class="kb-screenshot-figure">
  <img src="/assets/knowledgebase/screenshots/tenant/03-client-install.png" width="1440" height="960" alt="Clients 的 Install 页面显示 Windows、Android 和 Linux 安装入口">
  <figcaption>演示截图：按钮和同步时间用于说明位置，不代表你的设备状态。</figcaption>
</figure>
<ol>
  <li><strong>Windows：</strong>打开 <strong>Tenant → Clients</strong>，选择 <strong>Download Windows App</strong>。</li>
  <li>安装并打开应用，填写 <strong>Master URL</strong>、<strong>Email</strong> 和 <strong>Password</strong>。</li>
  <li>选择 <strong>Connect</strong>，确认出现 <strong>Connection successful!</strong>，全局状态为 <strong>Connected</strong>，可用转发显示 <strong>Running</strong>。</li>
  <li><strong>Android：</strong>在 arm64、Android 8.0+ 设备上安装应用并登录。</li>
  <li>同意 Android 的 VPN 授权，只选择应用列出的受支持 LeiGod 服务。</li>
  <li>观察状态从 <strong>Ready to connect</strong> 经过 <strong>Connecting...</strong> 变为 <strong>Connected</strong>。不要据此承诺 Android 支持 Race 或 MPTCP。</li>
  <li><strong>Linux：</strong>打开 <strong>Tenant → Clients</strong>，展开 <strong>Linux Install</strong>，选择 <strong>Copy Install Command</strong>。</li>
  <li>以相当于 root 的权限运行一次复制的命令；不要把它粘贴到聊天、工单、日志或共享历史记录。</li>
  <li>确认安装器输出 <code>installed leikwan-client-agent</code>。</li>
  <li>回到面板查看最近同步；也可在主机上确认 <code>config_pull_state.json</code> 的 <code>status</code> 为 <code>ok</code>。</li>
</ol>

<h2>如何确认成功</h2>
<p>面板应显示预期客户端的最近同步。Windows 同时显示 <strong>Connection successful!</strong>、<strong>Connected</strong> 和转发的 <strong>Running</strong>；Android 到达 <strong>Connected</strong>；Linux 安装完成且拉取状态正常。之后仍需单独验证真实应用连接。</p>

<h2>如果没有成功</h2>
<p><strong>先停止公开凭据或反复运行秘密命令。</strong>核对 Master URL、设备时间、网络和权限。Android 要确认 VPN 授权；Linux 要确认 systemd 与 Python 3 可用。收集错误时删除代理身份验证令牌等秘密内容。</p>

<h2>相关指南</h2>
<ul>
  <li><a href="/knowledgebase?thread=getting-started-five-minute-overview">五分钟完成首次连接</a></li>
  <li><a href="/knowledgebase?thread=connect-and-verify">连接并验证</a></li>
  <li><a href="/knowledgebase?thread=troubleshooting-common-mistakes">排查常见问题</a></li>
</ul>
',
    status = 'published',
    published_at = COALESCE(published_at, CURRENT_TIMESTAMP),
    updated_at = CURRENT_TIMESTAMP
WHERE topic_id IN (SELECT id FROM knowledgebase_topics WHERE topic_key = 'install-client')
  AND locale = 'zh-Hans'
  AND SHA2(body_html, 256) = 'f3aa04a602a3faa3084ec87ad726680ef4b19e1595bf408359a76eb3b259b1a4';

UPDATE knowledgebase_articles
SET title = '创建第一条转发',
    excerpt = '选择线路方案、填写目标地址并记下系统分配的本地端口。',
    body_html = '<h2>你将完成什么</h2>
<p>你将从已生效的套餐创建第一条转发，只填写表单中看得见的目标和传输选项，并记下应用后续连接所需的本地端口。</p>

<h2>开始前准备</h2>
<ul>
  <li>确认套餐或服务状态为<strong>有效</strong>。</li>
  <li>准备目标服务的主机名或 IP 地址。</li>
  <li>确认目标端口，以及应用使用 TCP、UDP 还是两者。</li>
  <li>不要猜测表单之外的内部设置。</li>
</ul>

<h2>操作步骤</h2>
<pre class="mermaid">flowchart TD
accTitle: 第一条转发表单路径
accDescr: 打开有效套餐，选择转发线路，填写目标和传输方式，保存后等待预配完成并记下系统分配的本地端口。
A[打开有效套餐] --&gt; B[选择 Forward Route]
B --&gt; C[填写 Target Host 和 Target Port]
C --&gt; D[选择 Transport]
D --&gt; E[保存]
E --&gt; F[等待转发可用]
F --&gt; G[记下分配的本地端口]</pre>
<p>第一张表单只需要线路方案、目标地址、目标端口、本地端口偏好和传输类型。保存会启动预配流程，系统分配的本地端口是下一步连接客户端时要使用的值。</p>
<figure class="kb-screenshot-figure">
  <img src="/assets/knowledgebase/screenshots/tenant/02-forward-form.png" width="1440" height="960" alt="Create Forward 表单显示 Forward Route、Target Host、Target Port、Local Listen Port 和 Transport">
  <figcaption>演示截图：如果没有指定端口，把 Local Listen Port 留空，让面板分配。</figcaption>
</figure>
<ol>
  <li>打开已生效的服务，选择 <strong>Create Forward</strong>。</li>
  <li>在 <strong>Forward Route</strong> 中选择系统提供的线路方案。</li>
  <li>在 <strong>Target Host</strong> 填写目标主机名或 IP。</li>
  <li>在 <strong>Target Port</strong> 填写目标服务端口。</li>
  <li>没有指定安全端口时，将 <strong>Local Listen Port</strong> 留空，让系统分配本地端口。</li>
  <li>在 <strong>Transport</strong> 中为仅使用 TCP 的应用选择 <strong>TCP</strong>。</li>
  <li>应用同时需要两种传输时选择 <strong>TCP + UDP</strong>，仅使用 UDP 时才选择 <strong>UDP</strong>。</li>
  <li>选择 <strong>Create Forward</strong> 完成保存。</li>
  <li>等待预配完成，再从转发卡片记下系统分配的本地端口。</li>
</ol>

<h2>如何确认成功</h2>
<p>新转发卡片应显示预期的目标和本地端口。保存记录、预配完成、客户端最近同步、显示 <strong>Running</strong> 和应用成功是五个不同检查点；先等待配置可用，再在客户端同步后验证真实请求。</p>

<h2>如果没有成功</h2>
<p><strong>先停止猜测隐藏选项。</strong>如果没有可选的 Forward Route，或目标地址不确定，请确认套餐有效，只修正上述可见字段，并联系管理员发布合适的线路方案。</p>

<h2>相关指南</h2>
<ul>
  <li><a href="/knowledgebase?thread=install-client">安装客户端</a></li>
  <li><a href="/knowledgebase?thread=connect-and-verify">连接并验证</a></li>
  <li><a href="/knowledgebase?thread=glossary">名词解释</a></li>
</ul>
',
    status = 'published',
    published_at = COALESCE(published_at, CURRENT_TIMESTAMP),
    updated_at = CURRENT_TIMESTAMP
WHERE topic_id IN (SELECT id FROM knowledgebase_topics WHERE topic_key = 'getting-started-first-forward-checklist')
  AND locale = 'zh-Hans'
  AND SHA2(body_html, 256) = '2f29dd98a42d184e4b35e7020cf58d0fb5e17075dbb4531a58f6cec97631e5c6';

UPDATE knowledgebase_articles
SET title = '连接并验证',
    excerpt = '选择正确的客户端地址，测试服务并确认转发已经工作。',
    body_html = '<h2>你将完成什么</h2>
<p>你将根据应用所在设备选择正确的客户端地址，通过转发发出真实请求，并区分面板状态、运行连接和目标应用成功这几类证据。</p>

<h2>开始前准备</h2>
<ul>
  <li>记下转发的本地端口。</li>
  <li>确认客户端有最近同步，转发显示 <strong>Running</strong>。</li>
  <li>确认应用与客户端在同一台设备上，还是位于另一台设备。</li>
  <li>准备符合应用协议的测试；仅检查 TCP 端口不能证明 HTTP、SSH、数据库或游戏请求可用。</li>
</ul>

<h2>操作步骤</h2>
<pre class="mermaid">flowchart TD
accTitle: 选择客户端连接地址
accDescr: 应用与客户端同机时使用回环地址，跨设备时仅使用可达的 Windows 或 Linux 客户端主机地址并按需配置网络规则，Android 保持仅回环监听。
A[确认应用所在设备] --&gt; B{与客户端同机吗}
B -- 是 --&gt; C[使用 127.0.0.1 和本地端口]
B -- 否 --&gt; D{客户端平台}
D -- Windows 或 Linux --&gt; E[使用可达主机地址和端口]
E --&gt; F[按需允许防火墙安全组或 NAT]
D -- Android --&gt; G[保持仅回环监听]
C --&gt; H[测试实际应用协议]
F --&gt; H
G --&gt; H</pre>
<p>地址选择取决于应用在哪里运行。同机连接使用回环地址；跨设备只能通过网络可达的 Windows 或 Linux 客户端主机，并可能需要有限的网络放行；Android 的监听默认仅限回环，除非另有受支持的暴露方案。</p>
<figure class="kb-screenshot-figure">
  <img src="/assets/knowledgebase/screenshots/tenant/04-forward-result.png" width="1440" height="960" alt="转发结果卡片显示本地监听地址、目标、传输类型和 Running 状态">
  <figcaption>演示截图：连接应用时使用你自己的 Forward 卡片中的本地监听值。</figcaption>
</figure>
<ol>
  <li>应用与客户端同机时，填写 <code>127.0.0.1</code> 和转发的本地端口。</li>
  <li>另一台设备需要访问 Windows 或 Linux 客户端时，填写可访问的 Windows 或 Linux 客户端主机地址和相同端口。</li>
  <li>只在主机防火墙中放行所需端口。</li>
  <li>云主机按需在安全组中放行该端口。</li>
  <li>客户端位于路由器后方时，仅在网络负责人批准后配置 NAT。</li>
  <li>不要把 Android 当作跨设备监听器；没有单独暴露方案时，它仅监听回环地址。</li>
  <li>使用实际应用协议发出请求。</li>
  <li>检查应用响应；可查看目标端时，再核对目标日志。</li>
</ol>

<h2>如何确认成功</h2>
<p>应用通过所选客户端地址和本地端口获得预期响应，目标日志也能对应到该请求时，连接才真正通过。<strong>Connected</strong>、<strong>Running</strong> 和开放的本地端口都是中间状态，不能单独证明目标应用已处理请求。</p>

<h2>如果没有成功</h2>
<p><strong>先停止开放大范围端口；公网 IP 并非默认连接入口。</strong>重新核对同机或跨设备地址、本地端口和目标服务；先从目标主机测试后端，再提交已去除秘密的错误、时间、客户端平台和转发名称。</p>

<h2>相关指南</h2>
<ul>
  <li><a href="/knowledgebase?thread=install-client">安装客户端</a></li>
  <li><a href="/knowledgebase?thread=troubleshooting-common-mistakes">排查常见问题</a></li>
  <li><a href="/knowledgebase?thread=glossary">名词解释</a></li>
</ul>
',
    status = 'published',
    published_at = COALESCE(published_at, CURRENT_TIMESTAMP),
    updated_at = CURRENT_TIMESTAMP
WHERE topic_id IN (SELECT id FROM knowledgebase_topics WHERE topic_key = 'connect-and-verify')
  AND locale = 'zh-Hans'
  AND SHA2(body_html, 256) = '43571b3081556524ad3e4c3b9e0f0c2cc01adc88e20b728968beea378e1135b9';

UPDATE knowledgebase_articles
SET title = '选择连接模式',
    excerpt = '按稳定、容错或多路径需求理解 Single、Race 和 Bond。',
    body_html = '<h2>你将完成什么</h2>
<p>你将按稳定、容错和多路径需求理解 Single、Race 与 Bond，为转发选择已被线路方案和客户端平台支持的连接模式，并避开无法保证的性能承诺。</p>

<h2>开始前准备</h2>
<ul>
  <li>如果条件允许，先让一条基础转发稳定工作。</li>
  <li>阅读套餐发布的线路约定，并确认客户端平台。</li>
  <li>分清自己需要的是简单稳定、路径容错，还是明确受支持的 Bond 方案。</li>
  <li>不要只因为名称听起来更快就更改模式。</li>
</ul>

<h2>操作步骤</h2>
<pre class="mermaid">flowchart TD
accTitle: 连接模式选择路径
accDescr: 需要最简单的稳定路径时选择 Single，需要受支持的非聚合容错时选择 Race，只有方案明确支持且要求均已核实时才选择 Bond。
A[你需要什么结果] --&gt; B{最简单的稳定路径吗}
B -- 是 --&gt; C[选择 Single]
B -- 否 --&gt; D{需要非聚合容错吗}
D -- 是 --&gt; E[选择 Race]
D -- 否 --&gt; F{方案明确支持 Bond 吗}
F -- 是 --&gt; G[选择 Bond 并核对要求]
F -- 否 --&gt; H[咨询管理员]</pre>
<p>安全默认选项是 Single。Race 用于受支持的路径选择或容错，不会合并带宽；Bond 只适用于发布的线路约定明确支持该套餐和平台的情况。</p>
<figure class="kb-screenshot-figure">
  <img src="/assets/knowledgebase/screenshots/tenant/04-forward-result.png" width="1440" height="960" alt="转发结果页显示用户可见的线路结果，而不是内部 Single、Race 或 Bond 组合">
  <figcaption>演示截图：租户检查线路结果和状态，内部拓扑由方案管理。</figcaption>
</figure>
<ol>
  <li>需要最简单、稳定的连接时选择 <strong>Single</strong>。</li>
  <li>线路约定提供至少两个可用端点，且需要不聚合带宽的容错时选择 <strong>Race</strong>。</li>
  <li>Race 不是带宽聚合。</li>
  <li>Linux 的 TCP Race 通常每条连接选择一个胜出路径。</li>
  <li>Linux 的 UDP Race 可能把数据报发送到多个可用路径。</li>
  <li>Race 的具体行为因客户端平台而异，Android 不承诺 Race 或 MPTCP。</li>
  <li>只有已发布的线路约定明确支持时才选择 <strong>Bond</strong>。</li>
  <li>逐项核对 Bond 要求；仅在线路约定要求时使用 Sidecar。</li>
  <li>支持范围不清楚时咨询管理员，不要猜测。</li>
</ol>

<h2>如何确认成功</h2>
<p>连接模式必须同时符合套餐约定和客户端平台能力，真实应用也要在预期条件下成功。Race 应验证一个可用路径失效后受支持的请求仍能继续；Bond 应核对约定和实际结果，不能根据状态推断速度翻倍、跑满带宽或所有路径都被使用。</p>

<h2>如果没有成功</h2>
<p><strong>先停止承诺加速倍数或所有路径同时工作。</strong>如果 Single 可用且稳定，先恢复 Single，记录失败的平台和线路方案，再请管理员确认所需的 Race 或 Bond 行为是否受支持。</p>

<h2>相关指南</h2>
<ul>
  <li><a href="/knowledgebase?thread=bond-sidecar-setup">配置 Sidecar</a></li>
  <li><a href="/knowledgebase?thread=troubleshooting-common-mistakes">排查常见问题</a></li>
  <li><a href="/knowledgebase?thread=glossary">名词解释</a></li>
</ul>
',
    status = 'published',
    published_at = COALESCE(published_at, CURRENT_TIMESTAMP),
    updated_at = CURRENT_TIMESTAMP
WHERE topic_id IN (SELECT id FROM knowledgebase_topics WHERE topic_key = 'connection-modes-single-race-bond')
  AND locale = 'zh-Hans'
  AND SHA2(body_html, 256) = '2aa87344e6731b36df3090def47ae9d2029ca58c14112827bc894a4ba5c066bb';

UPDATE knowledgebase_articles
SET title = '配置 Sidecar',
    excerpt = '仅在线路方案明确需要时创建并验证 Sidecar。',
    body_html = '<h2>你将完成什么</h2>
<p>你将仅在线路方案明确要求时创建并验证 Sidecar。Sidecar 是目标侧的中间传输组件，不是目标应用，也不是 Bond 转发的通用必备组件。</p>

<h2>开始前准备</h2>
<ul>
  <li>确认所选 Bond 套餐已发布的线路约定明确要求 Sidecar。</li>
  <li>准备一台可以访问后端服务的目标侧主机。</li>
  <li>向管理员确认 <strong>Sidecar Host</strong> 和 Bond 传输端口。</li>
  <li>准备 Sidecar 主机上相当于 root 的权限。</li>
  <li>面板生成的安装命令和所有凭据都必须保密。</li>
</ul>

<h2>操作步骤</h2>
<pre class="mermaid">flowchart LR
accTitle: 使用 Sidecar 的 Bond 线路
accDescr: 客户端经过托管的 Slave 路径到达中间 Sidecar，再由 Sidecar 连接目标服务。
A[客户端] --&gt; B[托管 Slave 路径]
B --&gt; C[Sidecar]
C --&gt; D[目标服务]</pre>
<p>Sidecar 位于托管路径与目标服务之间。它既要在线，也要能访问后端监听；但最终仍需通过真实应用请求单独验证目标服务。</p>
<figure class="kb-screenshot-figure">
  <img src="/assets/knowledgebase/screenshots/tenant/06-target-endpoint.png" width="1440" height="960" alt="Target Endpoints 表单显示主机和三个监听端口字段">
  <figcaption>演示截图：只有已发布线路约定要求时，才创建租户自有 endpoint。</figcaption>
</figure>
<ol>
  <li>在 <strong>Advanced</strong> 下打开 <strong>Target Endpoints</strong>。</li>
  <li>选择 <strong>Create</strong> 标签页。</li>
  <li>在 <strong>Create Transport Endpoint</strong> 中填写容易识别的 <strong>Name</strong>。</li>
  <li>填写管理员确认的 <strong>Sidecar Host</strong>。</li>
  <li>在 <strong>Transport Listen Port</strong> 填写获准的 Bond 传输端口。</li>
  <li>选择 <strong>Save Transport Endpoint</strong>。</li>
  <li>在卡片中打开 <strong>Install Command</strong> 并复制，但不要展示命令内容。</li>
  <li>以相当于 root 的权限在 Sidecar 主机上运行命令。</li>
  <li>回到面板，等待 <strong>Last Heartbeat</strong>、<strong>Agent Version</strong> 和 <strong>Last Status</strong> 更新。</li>
  <li>从 Sidecar 主机直接测试配置目标地址和端口上的后端监听服务。</li>
  <li>根据已发布线路约定创建或刷新 Bond 转发，并验证客户端连接。</li>
</ol>

<h2>如何确认成功</h2>
<p>Sidecar 应报告最近心跳和预期代理状态，Bond 转发也应能把真实应用请求送到目标。仅有心跳不能证明后端健康；必须另行测试后端监听服务，并尽可能在目标日志中确认请求。</p>

<h2>如果没有成功</h2>
<p><strong>先停止随意更改端口、地址或线路设置。</strong>没有心跳时检查 Sidecar 服务与出站网络；心跳正常但应用失败时，从 Sidecar 主机测试后端。联系支持时不要提交令牌、密码、命令、密钥、会话或线路材料。</p>

<h2>相关指南</h2>
<ul>
  <li><a href="/knowledgebase?thread=connection-modes-single-race-bond">选择连接模式</a></li>
  <li><a href="/knowledgebase?thread=connect-and-verify">连接并验证</a></li>
  <li><a href="/knowledgebase?thread=troubleshooting-common-mistakes">排查常见问题</a></li>
</ul>
',
    status = 'published',
    published_at = COALESCE(published_at, CURRENT_TIMESTAMP),
    updated_at = CURRENT_TIMESTAMP
WHERE topic_id IN (SELECT id FROM knowledgebase_topics WHERE topic_key = 'bond-sidecar-setup')
  AND locale = 'zh-Hans'
  AND SHA2(body_html, 256) = 'b41cb1289f0ca9f87d84918d508ae28233c2fc44b066ede338e3db8577c3a225';

UPDATE knowledgebase_articles
SET title = '排查常见问题',
    excerpt = '从看得见的症状开始，按最短且安全的顺序恢复连接。',
    body_html = '<h2>你将完成什么</h2>
<p>你将从看得见的连接症状开始，沿客户端、转发、线路和目标服务逐段检查，修复第一个失败边界，而不是用随机改动掩盖原问题。</p>

<h2>开始前准备</h2>
<ul>
  <li>记下准确错误、发生时间、客户端平台、转发名称和本地端口。</li>
  <li>检查期间保留已保存的转发，不要先改线路拓扑。</li>
  <li>截图和支持消息中不得包含密码、令牌、生成命令、密钥、会话或线路材料。</li>
  <li>最终重试必须使用真实应用协议。</li>
</ul>

<h2>操作步骤</h2>
<pre class="mermaid">flowchart TD
accTitle: 从症状开始排查连接
accDescr: 请求失败后依次检查最近同步、客户端地址、端口访问、转发与线路健康和目标服务，最后再使用诊断与支持。
A[应用请求失败] --&gt; B{最近同步正常吗}
B -- 否 --&gt; C[恢复客户端同步]
B -- 是 --&gt; D{地址正确吗}
D -- 否 --&gt; E[使用回环或可达客户端主机]
D -- 是 --&gt; F{端口已放行吗}
F -- 否 --&gt; G[只放行所需端口]
F -- 是 --&gt; H{转发和线路健康吗}
H -- 否 --&gt; I[打开 View Diagnostics]
H -- 是 --&gt; J[从目标主机测试服务]
C --&gt; K[重试应用]
E --&gt; K
G --&gt; K
I --&gt; L[整理诊断并联系支持]
J --&gt; L</pre>
<p>排查顺序从客户端逐步走向目标端。发现第一个失败边界后只修复该处，再重试一次，同时保存错误证据，避免随机修改拓扑。</p>
<figure class="kb-screenshot-figure">
  <img src="/assets/knowledgebase/screenshots/tenant/05-diagnostics.png" width="1440" height="960" alt="客户端诊断页面显示先 Run Diagnostics 再 View Diagnostics，并显示健康结果">
  <figcaption>演示截图：先运行一次新诊断，再打开 View Diagnostics 查看详情。</figcaption>
</figure>
<ol>
  <li>确认预期客户端有最近同步。</li>
  <li>确认转发显示 <strong>Running</strong>。</li>
  <li>检查地址是否正确：应用同机时使用 <code>127.0.0.1</code>。</li>
  <li>跨设备时只能使用可达的 Windows 或 Linux 客户端主机地址，Android 不作为跨设备监听器。</li>
  <li>确认本地端口与转发卡片一致。</li>
  <li>跨设备连接时确认端口已放行，并只在适用的防火墙、安全组和 NAT 中开放该端口。</li>
  <li>在 <strong>View Diagnostics</strong> 检查转发和线路是否健康。</li>
  <li>先从目标服务自己的主机测试目标服务。</li>
  <li>使用实际应用协议重新测试。</li>
  <li>仍然失败时，整理已脱敏的诊断信息并联系支持人员。</li>
</ol>

<h2>如何确认成功</h2>
<p>第一个失败边界恢复后，真实应用请求应成功。最近同步只证明配置送达，<strong>Running</strong> 只证明本地进程运行，目标应用返回正确结果才证明整条连接完成。</p>

<h2>如果没有成功</h2>
<p><strong>先停止全部重装、开放大范围端口或随机更改拓扑。</strong>保存错误与诊断，向支持提供转发名称、客户端平台、测试时间、地址类型、本地端口和脱敏结果，绝不发送秘密。</p>

<h2>相关指南</h2>
<ul>
  <li><a href="/knowledgebase?thread=install-client">安装客户端</a></li>
  <li><a href="/knowledgebase?thread=connect-and-verify">连接并验证</a></li>
  <li><a href="/knowledgebase?thread=glossary">名词解释</a></li>
</ul>
',
    status = 'published',
    published_at = COALESCE(published_at, CURRENT_TIMESTAMP),
    updated_at = CURRENT_TIMESTAMP
WHERE topic_id IN (SELECT id FROM knowledgebase_topics WHERE topic_key = 'troubleshooting-common-mistakes')
  AND locale = 'zh-Hans'
  AND SHA2(body_html, 256) = '353cf08132cab9bfbbcce50de61ceb970ed62692e661697b1e8027ff444fbeae';

UPDATE knowledgebase_articles
SET title = '五分鐘完成首次連線',
    excerpt = '按最短路徑完成方案、轉發、用戶端安裝和連線驗證。',
    body_html = '<h2>你將完成什麼</h2>
<p>你會從有效方案開始，完成一條轉發，安裝用戶端，並用真實應用程式確認首次連線。重點不只是把設定儲存起來，而是透過系統分配的本機連接埠送出要求，收到預期回應。</p>

<h2>開始前準備</h2>
<ul>
  <li>確認方案或服務標示為<strong>有效</strong>。</li>
  <li>準備目標位址、目標連接埠，以及應用程式使用 TCP、UDP 或兩者的資訊。</li>
  <li>準備一台支援的 Windows、Android 或 Linux 裝置安裝用戶端。</li>
  <li>不要分享密碼、權杖、複製的安裝指令、私密金鑰或線路資料。</li>
</ul>

<h2>操作步驟</h2>
<pre class="mermaid">flowchart TD
accTitle: 五分鐘首次連線流程
accDescr: 從有效方案開始，建立一條轉發，安裝並登入用戶端，測試分配的本機連接埠，再依連線是否成功完成設定或開始排查問題。
A[有效方案] --&gt; B[建立一條轉發]
B --&gt; C[安裝並登入用戶端]
C --&gt; D[測試分配的本機連接埠]
D --&gt; E{連線成功嗎}
E -- 是 --&gt; F[首次設定完成]
E -- 否 --&gt; G[開始排查問題]</pre>
<p>最短且安全的流程只有一個判斷點：應用程式要求成功，就代表首次設定完成；若失敗，保留已儲存的轉發，從看得見的症狀開始排查。</p>
<figure class="kb-screenshot-figure">
  <img src="/assets/knowledgebase/screenshots/tenant/01-overview.png" width="1440" height="960" alt="租戶概覽頁顯示三步設定流程和 Create a forward 操作">
  <figcaption>示範截圖：方案、數量和 ID 都是示範值，請以自己的面板為準。</figcaption>
</figure>
<ol>
  <li>在租戶面板開啟有效服務。</li>
  <li>選擇<strong>建立轉發</strong>。</li>
  <li>選擇面板提供的線路方案，填入目標位址和連接埠。</li>
  <li>儲存轉發，記下系統分配的本機連接埠。</li>
  <li>安裝用戶端並登入。</li>
  <li>等待面板出現最近同步。</li>
  <li>讓應用程式連到本機監聽位址與連接埠。</li>
  <li>使用實際應用協定測試，例如 HTTP 要求、SSH 連線或遊戲連線。</li>
</ol>

<h2>如何確認成功</h2>
<p>佈建完成後，面板會顯示有效端點，用戶端有最近同步，轉發則顯示 <strong>Connected</strong> 或 <strong>Running</strong>。這些是不同階段的狀態；完整證明仍是應用程式要求成功並取得預期結果。若你管理目標服務，也應能在記錄中看到要求抵達。</p>

<h2>如果沒有成功</h2>
<p><strong>請先停止隨意調整線路細節。</strong>保留第一條轉發，記下確切錯誤和時間，再開啟排查指南。設定已儲存、佈建完成、最近同步、程序執行中，以及應用程式成功，是五個不同的檢查點。</p>

<h2>相關指南</h2>
<ul>
  <li><a href="/knowledgebase?thread=getting-started-first-forward-checklist">建立第一條轉發</a></li>
  <li><a href="/knowledgebase?thread=install-client">安裝用戶端</a></li>
  <li><a href="/knowledgebase?thread=connect-and-verify">連線並驗證</a></li>
  <li><a href="/knowledgebase?thread=troubleshooting-common-mistakes">排查常見問題</a></li>
</ul>
',
    status = 'published',
    published_at = COALESCE(published_at, CURRENT_TIMESTAMP),
    updated_at = CURRENT_TIMESTAMP
WHERE topic_id IN (SELECT id FROM knowledgebase_topics WHERE topic_key = 'getting-started-five-minute-overview')
  AND locale = 'zh-TW'
  AND SHA2(body_html, 256) = 'b8141a6034ae77cee9d009c967e15bb69f5445735a2b5e9abeccc86ecb703b1e';

UPDATE knowledgebase_articles
SET title = '安裝用戶端',
    excerpt = '在 Windows、Android 或 Linux 上安裝並登入，然後確認用戶端在線。',
    body_html = '<h2>你將完成什麼</h2>
<p>你會在 Windows、Android 或 Linux 上安裝並登入用戶端，接著確認裝置已在線，並收到最近的轉發設定。</p>

<h2>開始前準備</h2>
<ul>
  <li>Windows 登入需要 App 顯示的 <strong>Master URL</strong>、<strong>Email</strong> 和 <strong>Password</strong>。</li>
  <li>Android 需要 arm64、Android 8.0+ 裝置，並準備允許系統建立 VPN 連線。</li>
  <li>Linux 主機需要 systemd、Python 3、可用的網路，以及等同 root 的權限。</li>
  <li>登入資訊和複製的指令都屬於機密；Linux 安裝指令內含用於 Agent 身分驗證權杖。</li>
</ul>

<h2>操作步驟</h2>
<pre class="mermaid">flowchart TD
accTitle: 用戶端安裝流程
accDescr: 依裝置的作業系統選擇 Windows、Android 或 Linux 安裝流程，完成後都要確認用戶端最近同步。
A[選擇裝置] --&gt; B{作業系統}
B -- Windows --&gt; C[下載 Windows App]
B -- Android --&gt; D[安裝 arm64 Android App]
B -- Linux --&gt; E[複製 Linux 安裝指令]
C --&gt; F[確認最近同步]
D --&gt; F
E --&gt; F</pre>
<p>三個平台的安裝入口和畫面狀態不同，但完成條件一致：目標裝置上的用戶端已連線，面板也顯示最近同步。</p>
<figure class="kb-screenshot-figure">
  <img src="/assets/knowledgebase/screenshots/tenant/03-client-install.png" width="1440" height="960" alt="Clients 的 Install 頁面顯示 Windows、Android 和 Linux 安裝入口">
  <figcaption>示範截圖：按鈕和同步時間只用來說明位置，不代表你的裝置狀態。</figcaption>
</figure>
<ol>
  <li><strong>Windows：</strong>開啟 <strong>Tenant → Clients → Install</strong>（租戶 → 線路 → Install），選擇 <strong>Download Windows App</strong>（下載 Windows App）。</li>
  <li>安裝並開啟 App，填入 <strong>Master URL</strong>、<strong>Email</strong> 和 <strong>Password</strong>。</li>
  <li>選擇 <strong>Connect</strong>，確認出現 <strong>Connection successful!</strong>，整體狀態為 <strong>Connected</strong>，可用轉發顯示 <strong>Running</strong>。</li>
  <li><strong>Android：</strong>在 arm64、Android 8.0+ 裝置安裝 App 並登入。</li>
  <li>允許 Android 的 VPN 要求，只選擇 App 列出的支援的 LeiGod 服務。</li>
  <li>觀察狀態從 <strong>Ready to connect</strong>，經過 <strong>Connecting...</strong>，最後成為 <strong>Connected</strong>。不能因此承諾 Android 支援 Race 或 MPTCP。</li>
  <li><strong>Linux：</strong>開啟 <strong>Tenant → Clients → Install</strong>（租戶 → 線路 → Install），展開 <strong>Linux Install</strong>（Linux 安裝），選擇 <strong>Copy Install Command</strong>（複製安裝指令）。</li>
  <li>以等同 root 的權限執行一次複製的指令；不要貼到聊天、工單、記錄或共用的 Shell 歷程。</li>
  <li>確認安裝程式輸出 <code>installed leikwan-client-agent</code>。</li>
  <li>回到面板查看最近同步；也可在主機確認 <code>config_pull_state.json</code> 的 <code>status</code> 為 <code>ok</code>。</li>
</ol>

<h2>如何確認成功</h2>
<p>面板會顯示預期用戶端的最近同步。Windows 也會出現 <strong>Connection successful!</strong>、整體 <strong>Connected</strong> 和轉發 <strong>Running</strong>；Android 到達 <strong>Connected</strong>；Linux 則完成安裝並成功拉取設定。之後仍要另外驗證真實應用程式連線。</p>

<h2>如果沒有成功</h2>
<p><strong>請先停止公開登入資料或重複執行含機密的指令。</strong>核對 Master URL、裝置時間、網路和必要權限。Android 要確認已允許 VPN；Linux 要確認 systemd 與 Python 3 可用。收集診斷時，請移除 Agent 身分驗證權杖等機密內容。</p>

<h2>相關指南</h2>
<ul>
  <li><a href="/knowledgebase?thread=getting-started-five-minute-overview">五分鐘完成首次連線</a></li>
  <li><a href="/knowledgebase?thread=connect-and-verify">連線並驗證</a></li>
  <li><a href="/knowledgebase?thread=troubleshooting-common-mistakes">排查常見問題</a></li>
</ul>
',
    status = 'published',
    published_at = COALESCE(published_at, CURRENT_TIMESTAMP),
    updated_at = CURRENT_TIMESTAMP
WHERE topic_id IN (SELECT id FROM knowledgebase_topics WHERE topic_key = 'install-client')
  AND locale = 'zh-TW'
  AND SHA2(body_html, 256) = 'd1284b44944cf7630fa648e480a5088a2fa1ed10e70719b93e83352ce9603906';

UPDATE knowledgebase_articles
SET title = '建立第一條轉發',
    excerpt = '選擇線路方案、填寫目標位址並記下系統分配的本機連接埠。',
    body_html = '<h2>你將完成什麼</h2>
<p>你會從有效方案建立一條轉發，只填寫畫面上看得到的目標設定，並記下應用程式之後要使用的本機連接埠。</p>

<h2>開始前準備</h2>
<ul>
  <li>確認方案或服務狀態為<strong>有效</strong>。</li>
  <li>準備目標服務的主機名稱或 IP 位址。</li>
  <li>準備目標服務使用的連接埠。</li>
  <li>確認應用程式需要 TCP、UDP，或兩者都需要。</li>
</ul>

<h2>操作步驟</h2>
<pre class="mermaid">flowchart TD
accTitle: 第一條轉發的表單流程
accDescr: 開啟有效方案，選擇轉發路由，填寫目標與傳輸方式，儲存後等待轉發可用，再記下分配的本機連接埠。
A[開啟有效方案] --&gt; B[選擇轉發路由]
B --&gt; C[填寫目標主機和目標連接埠]
C --&gt; D[選擇傳輸方式]
D --&gt; E[儲存]
E --&gt; F[等待轉發可用]
F --&gt; G[記下分配的本機連接埠]</pre>
<p>表單只需要線路方案、目標、偏好的本地連接埠和傳輸方式。儲存後會開始佈建；下一篇指南真正需要的是分配的本機連接埠。</p>
<figure class="kb-screenshot-figure">
  <img src="/assets/knowledgebase/screenshots/tenant/02-forward-form.png" width="1440" height="960" alt="Create Forward 表單顯示 Forward Route、Target Host、Target Port、Local Listen Port 和 Transport">
  <figcaption>示範截圖：沒有指定連接埠時，把 Local Listen Port 留空，讓面板分配。</figcaption>
</figure>
<ol>
  <li>開啟有效服務，選擇<strong>建立轉發</strong>。</li>
  <li>在 <strong>Forward Route</strong>（轉發路由）選擇面板提供的線路方案。</li>
  <li>在 <strong>Target Host</strong>（目標主機）填入目標位址。</li>
  <li>在 <strong>Target Port</strong>（目標連接埠）填入服務連接埠。</li>
  <li>除非已取得特定且安全的連接埠，否則將 <strong>Local Listen Port</strong>（本地監聽連接埠）留空，接受系統分配。</li>
  <li>在 <strong>Transport</strong>（傳輸）選擇 <strong>TCP</strong>，供只使用 TCP 的應用程式使用。</li>
  <li>應用程式同時需要兩種協定時，選擇 <strong>TCP + UDP</strong>。</li>
  <li>只有完全使用 UDP 的應用程式才選擇 <strong>UDP</strong>。</li>
  <li>選擇 <strong>Create Forward</strong>（建立轉發）以儲存。</li>
  <li>等待佈建完成，再從轉發卡片記下系統分配的本機連接埠。</li>
</ol>

<h2>如何確認成功</h2>
<p>新的轉發會顯示預期的目標和本機連接埠。資料已儲存只是第一個檢查點；接著要等設定啟用、用戶端最近同步，最後看到 <strong>Running</strong> 且應用程式成功收到回應。</p>

<h2>如果沒有成功</h2>
<p><strong>請先停止操作，特別是沒有可選的轉發路由，或目標資訊不確定時。</strong>不要猜測隱藏設定。先確認方案有效，只修正上述可見欄位；若沒有合適線路，請管理員發布可用的方案。</p>

<h2>相關指南</h2>
<ul>
  <li><a href="/knowledgebase?thread=install-client">安裝用戶端</a></li>
  <li><a href="/knowledgebase?thread=connect-and-verify">連線並驗證</a></li>
  <li><a href="/knowledgebase?thread=glossary">名詞解釋</a></li>
</ul>
',
    status = 'published',
    published_at = COALESCE(published_at, CURRENT_TIMESTAMP),
    updated_at = CURRENT_TIMESTAMP
WHERE topic_id IN (SELECT id FROM knowledgebase_topics WHERE topic_key = 'getting-started-first-forward-checklist')
  AND locale = 'zh-TW'
  AND SHA2(body_html, 256) = 'aa65e9e6dd9c936041ac0ee7176ad2fe6d49fd25773153f47dd7a59c3e84ad71';

UPDATE knowledgebase_articles
SET title = '連線並驗證',
    excerpt = '選擇正確的用戶端位址，測試服務並確認轉發已經運作。',
    body_html = '<h2>你將完成什麼</h2>
<p>你會依應用程式所在位置選擇正確的用戶端位址，透過轉發送出要求，並分清面板狀態和目標應用程式確實收到流量的證明。</p>

<h2>開始前準備</h2>
<ul>
  <li>記下轉發的本機連接埠。</li>
  <li>確認用戶端有最近同步，轉發顯示 <strong>Running</strong>。</li>
  <li>確認應用程式是在用戶端裝置，還是在另一台裝置執行。</li>
  <li>使用能理解協定的測試；只確認 TCP 連接埠開啟，不代表 HTTP、SSH、資料庫或遊戲要求能正確運作。</li>
</ul>

<h2>操作步驟</h2>
<pre class="mermaid">flowchart TD
accTitle: 選擇用戶端連線位址
accDescr: 應用程式與用戶端在同一台裝置時使用迴送位址；不同裝置使用可連到的 Windows 或 Linux 用戶端主機位址並視需要調整網路規則；Android 保持只監聽迴送位址。
A[確認應用程式所在裝置] --&gt; B{與用戶端在同一台裝置嗎}
B -- 是 --&gt; C[使用 127.0.0.1 和本機連接埠]
B -- 否 --&gt; D{用戶端平台}
D -- Windows 或 Linux --&gt; E[使用可連到的主機位址與連接埠]
E --&gt; F[視需要開放防火牆安全群組或 NAT]
D -- Android --&gt; G[保持只監聽迴送位址]
C --&gt; H[測試實際應用協定]
F --&gt; H
G --&gt; H</pre>
<p>決定使用哪個位址的關鍵，是應用程式在哪裡執行。同一台裝置使用迴送位址；跨裝置只能透過可連到的 Windows 或 Linux 用戶端主機，且可能需要調整網路存取規則。Android 仍維持只監聽迴送位址，除非另有核准且支援的架構。</p>
<figure class="kb-screenshot-figure">
  <img src="/assets/knowledgebase/screenshots/tenant/04-forward-result.png" width="1440" height="960" alt="轉發結果卡片顯示本機監聽位址、目標、傳輸方式和 Running 狀態">
  <figcaption>示範截圖：連線應用程式時，使用自己 Forward 卡片上的本機監聽值。</figcaption>
</figure>
<ol>
  <li>應用程式在用戶端裝置時，填入 <code>127.0.0.1</code> 和轉發的本機連接埠。</li>
  <li>其他裝置要透過 Windows 或 Linux 用戶端連線時，填入可連到的 Windows 或 Linux 用戶端主機位址和相同連接埠。</li>
  <li>跨裝置使用時，只在主機防火牆開放該連接埠。</li>
  <li>若使用雲端主機，也要在安全群組開放相同連接埠。</li>
  <li>用戶端位於路由器後方時，只有在網路管理者核准後才設定 NAT。</li>
  <li>不要把 Android 描述成跨裝置監聽服務；除非有核准的支援設計，否則它只監聽迴送位址。</li>
  <li>用實際應用協定送出要求。</li>
  <li>檢查回應；若能查看目標服務記錄，也要確認要求確實抵達。</li>
</ol>

<h2>如何確認成功</h2>
<p>應用程式透過用戶端位址和本機連接埠取得預期回應。最有力的證明是目標服務記錄中有對應要求。<strong>Connected</strong>、<strong>Running</strong> 和本機連接埠開啟都很有用，但任何一項都不能單獨證明目標應用程式已處理要求。</p>

<h2>如果沒有成功</h2>
<p><strong>請先停止開放大範圍防火牆規則，也不要假設任何公用 IP 都能使用。</strong>重新確認是同裝置或跨裝置、核對本機連接埠，並從目標服務所在主機測試服務。接著查看診斷，或向支援提供錯誤、時間、用戶端平台和轉發名稱，但不要附上機密。</p>

<h2>相關指南</h2>
<ul>
  <li><a href="/knowledgebase?thread=install-client">安裝用戶端</a></li>
  <li><a href="/knowledgebase?thread=troubleshooting-common-mistakes">排查常見問題</a></li>
  <li><a href="/knowledgebase?thread=glossary">名詞解釋</a></li>
</ul>
',
    status = 'published',
    published_at = COALESCE(published_at, CURRENT_TIMESTAMP),
    updated_at = CURRENT_TIMESTAMP
WHERE topic_id IN (SELECT id FROM knowledgebase_topics WHERE topic_key = 'connect-and-verify')
  AND locale = 'zh-TW'
  AND SHA2(body_html, 256) = '8f0d5c65c92cbe06955b2a1644324e695714eb06b91eb87e62b7f160aabbe6a3';

UPDATE knowledgebase_articles
SET title = '選擇連線模式',
    excerpt = '按穩定、容錯或多路徑需求理解 Single、Race 和 Bond。',
    body_html = '<h2>你將完成什麼</h2>
<p>你會按照穩定、容錯與多路徑需求理解 Single、Race 和 Bond，為轉發選擇線路方案及用戶端平台支援的連線模式，同時避開無法保證的效能承諾。</p>

<h2>開始前準備</h2>
<ul>
  <li>如果可以，先讓一條基本轉發穩定運作。</li>
  <li>閱讀方案已發布的線路約定，並確認用戶端平台。</li>
  <li>分清楚你需要的是簡單穩定、路徑容錯，或明確支援的 Bond 架構。</li>
  <li>不要只因模式名稱聽起來比較快就更換設定。</li>
</ul>

<h2>操作步驟</h2>
<pre class="mermaid">flowchart TD
accTitle: 連線模式選擇流程
accDescr: 需要最簡單的穩定路徑時選擇 Single，需要支援的非聚合容錯時選擇 Race，只有方案明確支援且要求都已核對時才選擇 Bond。
A[你需要什麼結果] --&gt; B{最簡單的穩定路徑嗎}
B -- 是 --&gt; C[選擇 Single]
B -- 否 --&gt; D{需要非聚合容錯嗎}
D -- 是 --&gt; E[選擇 Race]
D -- 否 --&gt; F{方案明確支援 Bond 嗎}
F -- 是 --&gt; G[選擇 Bond 並核對要求]
F -- 否 --&gt; H[詢問管理員]</pre>
<p>安全的預設值是 Single。Race 用於支援的路徑選擇或容錯，不會合併頻寬；Bond 只有在線路約定明確支援該方案與平台時，才是合適的進階選項。</p>
<figure class="kb-screenshot-figure">
  <img src="/assets/knowledgebase/screenshots/tenant/04-forward-result.png" width="1440" height="960" alt="轉發結果頁顯示使用者可見的線路結果，而不是內部 Single、Race 或 Bond 組合">
  <figcaption>示範截圖：租戶檢查線路結果和狀態，內部拓撲由方案管理。</figcaption>
</figure>
<ol>
  <li>需要最簡單且穩定的連線時，選擇 <strong>Single</strong>。</li>
  <li>線路約定提供至少兩個可用端點，而且需要不聚合頻寬的容錯時，選擇 <strong>Race</strong>。</li>
  <li>Race 不是頻寬聚合。</li>
  <li>Linux 的 TCP Race 通常會為每條連線選出一條勝出路徑。</li>
  <li>Linux 的 UDP Race 可能把資料包送往多條可用路徑。</li>
  <li>Race 的實際行為依用戶端平台而異；Android 不承諾 Race 或 MPTCP。</li>
  <li>只有已發布的線路約定明確支援時，才選擇 <strong>Bond</strong>。</li>
  <li>逐項核對 Bond 的要求；僅在線路約定要求時使用 Sidecar。</li>
  <li>支援範圍不清楚時詢問管理員，不要自行猜測。</li>
</ol>

<h2>如何確認成功</h2>
<p>連線模式必須同時符合方案約定和用戶端平台能力，真實應用程式也要在預期條件下成功。Race 應驗證一條可用路徑失效後，受支援的要求仍可繼續；Bond 應核對約定與實際結果，不能只從狀態推論速度加倍、跑滿頻寬或使用所有路徑。</p>

<h2>如果沒有成功</h2>
<p><strong>請先停止承諾加速倍數或所有路徑會同時運作。</strong>若 Single 可用且穩定，先改回 Single，記下失敗的平台和線路方案，再請管理員確認需要的 Race 或 Bond 行為是否受支援。</p>

<h2>相關指南</h2>
<ul>
  <li><a href="/knowledgebase?thread=bond-sidecar-setup">設定 Sidecar</a></li>
  <li><a href="/knowledgebase?thread=troubleshooting-common-mistakes">排查常見問題</a></li>
  <li><a href="/knowledgebase?thread=glossary">名詞解釋</a></li>
</ul>
',
    status = 'published',
    published_at = COALESCE(published_at, CURRENT_TIMESTAMP),
    updated_at = CURRENT_TIMESTAMP
WHERE topic_id IN (SELECT id FROM knowledgebase_topics WHERE topic_key = 'connection-modes-single-race-bond')
  AND locale = 'zh-TW'
  AND SHA2(body_html, 256) = '45a5c457d24fc43393edd484ae7e92e1885d5ccd25c06e05eb09655ed6715f9f';

UPDATE knowledgebase_articles
SET title = '設定 Sidecar',
    excerpt = '僅在線路方案明確需要時建立並驗證 Sidecar。',
    body_html = '<h2>你將完成什麼</h2>
<p>只有 Bond 方案已發布的線路約定明確要求時，你才會建立並驗證 Sidecar。Sidecar 是目標端的中介傳輸元件，不是目標應用程式。</p>

<h2>開始前準備</h2>
<ul>
  <li>確認所選 Bond 方案明確要求 Sidecar。</li>
  <li>準備一台可以連到後端服務的目標端主機。</li>
  <li>確認核准的 <strong>Sidecar 主機</strong> 與 Bond 傳輸連接埠。</li>
  <li>準備 Sidecar 主機上等同 root 的權限。</li>
  <li>面板產生的安裝指令與所有憑證都必須保密。</li>
</ul>

<h2>操作步驟</h2>
<pre class="mermaid">flowchart LR
accTitle: 使用 Sidecar 的 Bond 線路
accDescr: 用戶端經過受管理的 Slave 路徑與中介 Sidecar，最後連到目標服務。
A[用戶端] --&gt; B[受管理的 Slave 路徑]
B --&gt; C[Sidecar]
C --&gt; D[目標服務]</pre>
<p>Sidecar 位於受管理路徑與目標服務之間。它必須在線，也要能連到後端；但目標應用程式是否成功，仍需另外驗證。</p>
<figure class="kb-screenshot-figure">
  <img src="/assets/knowledgebase/screenshots/tenant/06-target-endpoint.png" width="1440" height="960" alt="Target Endpoints 表單顯示主機和三個監聽連接埠欄位">
  <figcaption>示範截圖：只有已發佈線路約定要求時，才建立租戶自有 endpoint。</figcaption>
</figure>
<ol>
  <li>在<strong>進階</strong>下開啟<strong>目標端點</strong>。</li>
  <li>選擇<strong>建立</strong>分頁。</li>
  <li>在<strong>建立傳輸端點</strong>填入容易辨識的<strong>名稱</strong>。</li>
  <li>填入核准的 <strong>Sidecar 主機</strong>。</li>
  <li>在<strong>傳輸監聽連接埠</strong>填入核准的 Bond 傳輸連接埠。</li>
  <li>選擇<strong>儲存傳輸端點</strong>。</li>
  <li>在端點卡片開啟<strong>安裝指令</strong>並複製，過程中不要暴露內容。</li>
  <li>以等同 root 的權限在 Sidecar 主機執行指令。</li>
  <li>回到面板，等待<strong>最後一次心跳</strong>、<strong>代理版本</strong>和<strong>最後狀態</strong>更新。</li>
  <li>從 Sidecar 主機測試設定的目標位址和連接埠上，後端監聽服務是否可連線。</li>
  <li>依已發布的線路約定建立或重新整理 Bond 轉發。</li>
</ol>

<h2>如何確認成功</h2>
<p>Sidecar 會回報最近的<strong>最後一次心跳</strong>和預期的 Agent 狀態，Bond 轉發也能把真實應用程式要求送到目標。只有心跳不能證明後端健康；請另外測試後端監聽服務，若可以，也在目標服務記錄中確認要求。</p>

<h2>如果沒有成功</h2>
<p><strong>請先停止隨意更改連接埠、位址或線路設定。</strong>沒有心跳時，檢查 Sidecar 服務和對外網路；心跳正常但應用程式失敗時，從 Sidecar 主機測試後端。向支援提供已清除機密的診斷，但不要分享產生的安裝指令、權杖、密碼、線路金鑰、私密金鑰、內部工作階段或線路資料。</p>

<h2>相關指南</h2>
<ul>
  <li><a href="/knowledgebase?thread=connection-modes-single-race-bond">選擇連線模式</a></li>
  <li><a href="/knowledgebase?thread=connect-and-verify">連線並驗證</a></li>
  <li><a href="/knowledgebase?thread=troubleshooting-common-mistakes">排查常見問題</a></li>
</ul>
',
    status = 'published',
    published_at = COALESCE(published_at, CURRENT_TIMESTAMP),
    updated_at = CURRENT_TIMESTAMP
WHERE topic_id IN (SELECT id FROM knowledgebase_topics WHERE topic_key = 'bond-sidecar-setup')
  AND locale = 'zh-TW'
  AND SHA2(body_html, 256) = '1c14601ce0ac93b97834b53fc8e7e23e9ca68052845aefc7dbfa579e94bf0189';

UPDATE knowledgebase_articles
SET title = '排查常見問題',
    excerpt = '從看得見的症狀開始，按最短且安全的順序恢復連線。',
    body_html = '<h2>你將完成什麼</h2>
<p>你會從看得見的症狀開始，一次檢查一個邊界，並在大範圍或隨意變更讓原始問題更難辨認前停下來。</p>

<h2>開始前準備</h2>
<ul>
  <li>記下確切錯誤、時間、用戶端平台、轉發名稱和本機連接埠。</li>
  <li>檢查目前狀態時，先不要更動已儲存的轉發。</li>
  <li>截圖或聯絡支援時，不要包含密碼、權杖、產生的指令、線路金鑰、私密金鑰或內部工作階段資料。</li>
  <li>最後一步必須使用實際應用協定測試。</li>
</ul>

<h2>操作步驟</h2>
<pre class="mermaid">flowchart TD
accTitle: 從症狀開始排查連線
accDescr: 應用程式要求失敗後依序檢查最近同步、位址、連接埠的網路存取、轉發與線路健康，以及目標服務，再使用診斷與支援。
A[應用程式要求失敗] --&gt; B{最近同步正常嗎}
B -- 否 --&gt; C[恢復用戶端同步]
B -- 是 --&gt; D{位址正確嗎}
D -- 否 --&gt; E[使用迴送位址或可連到的用戶端主機]
D -- 是 --&gt; F{連接埠已開放嗎}
F -- 否 --&gt; G[只開放必要連接埠]
F -- 是 --&gt; H{轉發和線路健康嗎}
H -- 否 --&gt; I[開啟查看診斷]
H -- 是 --&gt; J[從目標主機測試服務]
C --&gt; K[重試應用程式]
E --&gt; K
G --&gt; K
I --&gt; L[整理診斷後聯絡支援]
J --&gt; L</pre>
<p>檢查順序從用戶端一路走向目標。修正第一個失敗的邊界後重試一次，保留證據，不要隨意變更拓撲。</p>
<figure class="kb-screenshot-figure">
  <img src="/assets/knowledgebase/screenshots/tenant/05-diagnostics.png" width="1440" height="960" alt="用戶端診斷頁面顯示先 Run Diagnostics 再 View Diagnostics，並顯示健康結果">
  <figcaption>示範截圖：先執行一次新的診斷，再開啟 View Diagnostics 查看詳情。</figcaption>
</figure>
<ol>
  <li>確認預期的用戶端有最近同步。</li>
  <li>確認轉發顯示 <strong>Running</strong>。</li>
  <li>檢查位址是否正確：同一台用戶端裝置使用 <code>127.0.0.1</code>。</li>
  <li>其他裝置要連線時，使用可連到的 Windows 或 Linux 用戶端主機位址；不要把 Android 當成跨裝置監聽服務。</li>
  <li>確認本機連接埠和轉發卡片一致。</li>
  <li>跨裝置使用時，依實際環境確認防火牆、安全群組和 NAT 只讓必要的連接埠已開放。</li>
  <li>在<strong>查看診斷</strong>確認轉發和線路健康。</li>
  <li>從目標服務所在主機測試目標服務和連接埠。</li>
  <li>用實際應用協定重試。</li>
  <li>若仍然失敗，收集已清除機密的診斷，附上症狀和時間聯絡支援。</li>
</ol>

<h2>如何確認成功</h2>
<p>第一個失敗的邊界恢復正常，真實應用程式要求也成功。最近同步證明設定已送達，<strong>Running</strong> 證明本機程序狀態，目標成功回應才證明整段連線完成。</p>

<h2>如果沒有成功</h2>
<p><strong>請先停止重新安裝所有元件、開放大範圍連接埠或隨意變更拓撲。</strong>保留錯誤與診斷，向支援提供轉發名稱、用戶端平台、測試時間、位址類型、本機連接埠和已清理的結果，絕對不要傳送機密。</p>

<h2>相關指南</h2>
<ul>
  <li><a href="/knowledgebase?thread=install-client">安裝用戶端</a></li>
  <li><a href="/knowledgebase?thread=connect-and-verify">連線並驗證</a></li>
  <li><a href="/knowledgebase?thread=glossary">名詞解釋</a></li>
</ul>
',
    status = 'published',
    published_at = COALESCE(published_at, CURRENT_TIMESTAMP),
    updated_at = CURRENT_TIMESTAMP
WHERE topic_id IN (SELECT id FROM knowledgebase_topics WHERE topic_key = 'troubleshooting-common-mistakes')
  AND locale = 'zh-TW'
  AND SHA2(body_html, 256) = '186cfa2a63f2acb9dcc40790c53c4c5cdd2f922ec4682a04cd83c55150188962';

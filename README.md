# <img src="./public/impui-dynamic.svg" alt="IMPUI" width="220">

> "Give someone state and they'll have a bug one day, but teach them how to represent state in two separate locations that have to be kept in sync and they'll have bugs for a lifetime." -[ryg](https://twitter.com/rygorous/status/1507178315886444544)

IMPUI is a next generation full-stack SSR framework for building delightfully simple immediate mode flavored HTML user interfaces in PHP. Take PHP and HATEOAS to the next level with IMPUI!

Simplify your frontend logic by shifting state management to the backend. Drive your frontend from the backend using HTML attributes and a hypermedia-driven approach.

From simple CRUD style applications to massive collaborative multiplayer; IMPUI gives you the confidence to do both. Powered by [Datastar](https://data-star.dev/): *The Hypermedia Framework*.

- Immediate flavor! Less tedious synchronization and cross cutting concerns.
- Realtime updates! You can update your whole HTML at over 1000 FPS! Should you?
- Run anywhere! Start with PHP-FPM and move to persistent process for low latency push.
- No endpoint explosion! A button fires a named action; no routes, no controllers.

## Usage example

A basic counter. Truly the example of all examples. Exemplary.

```php
namespace app\counter;

use vektr3\imp;

class Counter
{
	public int $value = 0;
}

function counter()
{
	$c = imp\state(Counter::class);

	if (imp\action('increment')) {
		$c->value++;
	}
	if (imp\action('decrement')) {
		$c->value--;
	}
?>
	<h1>Count <?=imp\esc($c->value)?></h1>

	<button data-on:click="@imp('increment')">Increment</button>
	<button data-on:click="@imp('decrement')">Decrement</button>
<?
}
```

## Architecture

IMPUI belongs to the same hypermedia tradition as [HTMX](https://htmx.org) and is powered by [Datastar](http://data-star.dev/). The server renders the UI, the browser sends events up and applies the updates that come back.

The workhorse of this tradition is the morph: instead of replacing an element outright, incoming HTML is diffed against the live DOM and only the differences are applied, so focus, scroll position, input values and running animations survive the update. It is what lets server-rendered HTML feel like a smooth client-side app.

These libraries encourage moving state to the backend, but they stop short in two ways.

First, the server loses track of the page. Out of the box they update sections into targets: swap this element here, morph that one there. What the user is actually looking at becomes the sum of every operation, the server does not track that sum, and the client's view and the server's idea of it drift apart.

Second, the hypermedia layer alone cannot express real app behavior. Attributes and swaps cover navigation and forms well, but interactivity beyond that, toggles, multi-step flows, state that several parts of the page react to, is out of reach, so these stacks lean on a scripting companion like [Alpine](https://alpinejs.dev). That companion is its own state and logic living in the browser, outside what the server knows, which widens the first problem while solving the second.

Component frameworks like [Livewire](https://livewire.laravel.com) and [Phoenix LiveView](https://www.phoenixframework.org) get closer, but the unit is the component, so the page becomes islands that must be coordinated, and Livewire round-trips each component's state through the client on every request.

Datastar in particular (what powers IMPUI) points the same way: move state to the backend and drive the view from there. It hands you the direction and leaves the architecture to you. IMPUI makes that transition for you: it is the worked-out answer, so you do not have to design the state model, the session lifecycle and the update discipline yourself.

### The whole view is the unit

In IMPUI most of state is moved to the server and on every update renders and sends the whole view. Datastar has a name for this move and recommends it: the [fat morph](https://data-star.dev/guide/the_tao_of_datastar/#in-morph-we-trust), morphing the entire page on every update instead of aiming at targets.

IMPUI takes the recommendation and makes it the architecture: the fat morph is not one option among swap strategies, it is the update model everything else is built on. Fragment patches still exist as an optimization, for regions that need higher refresh rates than the rest of the page is worth re-sending for, but they never become islands: the next full-page update includes those regions and overwrites them, so the server's render stays the truth for almost everything on screen.

The client is a projection of the server state: the server always knows exactly (ideal) what the user is seeing, because it just sent it. No duplicate logic on both sides of the wire, no drift to reconcile, and a clean separation where the backend decides and the browser displays.

And sending the whole view is cheaper than it sounds: consecutive renders are nearly identical, so Server-Sent Events (SSE) with Brotli or Zstandard compression shrinks them to almost nothing, and the UI can be updated many times per second in soft real time. The stream is also push: the server updates the screen the moment something changes, on its own initiative, with no polling and no notify-then-fetch round trip.

That is not to say you cannot use polling or notify-then-fetch to run your IMPUI applications, it has been designed to support multiple transports and working modes to make it more practical.

### The immediate flavor

Sending the whole view on every update is where the immediate mode in the name comes from. In game and tool development, immediate mode GUI libraries like Dear ImGui rebuild the interface from current state on every frame instead of maintaining a retained widget tree that must be kept in sync with the data.

> Casey Muratori's [2005 lecture on immediate mode GUIs](https://www.youtube.com/watch?v=Z1qyvQsjK5Y) is a classic argument for why this beats retained-style libraries.

IMPUI applies the same idea to the web: every render pass is a fresh function of state that declares the whole view. The browser's DOM is still a retained structure, but reconciling it is the framework's job, done generically by the morph; your code never holds a reference to the UI, it only describes what the UI should be right now.

Working immediate mode also pays off in the code itself. State is plain values that the render reads on every pass, so cross-cutting concerns like permissions, feature flags or a global filter are checked where they apply instead of being wired through component trees and events.

This also shrinks the need for a scripting companion. Hypermedia frameworks tend to lean on a library like Alpine to cover the interactivity they cannot express, toggles, dropdowns, conditional visibility, small pieces of client state, which grows into a second codebase with its own state.

In IMPUI those behaviors are just state in the session context: the server flips a value, the next render reflects it, and interactions that other stacks push to client scripting stay in ordinary application code. Client-side code is still allowed where full server-side rendering (SSR) is impractical, through web components and plain JavaScript, but it is the exception rather than the architecture.

## Overview

The application code is written once, against one model: a page's state, logic and rendering live server side in a session context, one per browser tab; events come up, the rendered view goes down.

IMPUI supports multiple transports and working modes. On a plain PHP-FPM (FastCGI Process Manager) style host it runs stateless: each event is an ordinary HTTP request, the session context is loaded from storage (sealed or plain, whichever security profile you choose), the render runs, and the response carries the new view. Works on commodity PHP hosting.

On a persistent runtime it runs stateful: session state stays live in memory, the view streams down over SSE, and the server pushes updates the moment something changes. This is the mode for soft real-time UIs and server-initiated updates.

These are not separate products, and not even a single either-or switch: a deployment can run both modes at once. The working mode is decided per Imp, so the server chooses which sessions get a live stream and which are served request/response, and a running session can be upgraded or degraded between them: push when the page benefits and capacity allows, plain requests under load.

An application can start on PHP-FPM and grow into streaming without rewriting everything.

## Identity

Everything else in this document hangs off one question: how does a browser tab prove, continuously and across interruptions, that a particular session context is its own? The answer determines the security model, the privacy model and the legal posture at once, so it gets its own section.

Two names are used throughout this document, the protocol and the code: the **Imp** is the session context, the server held UI state for one tab, and the **True-Name** is the client held secret that identifies and unseals it.

As in the old stories, knowing an Imp's True-Name is what lets you wake it and command it, and nobody else can. The whole architecture fits in one sentence: the client holds the True-Name, the server holds the Imp, and neither is useful without the other.

### The True-Name

The True-Name identifies a tab and its Imp to the server. The client stores it in `sessionStorage`, which gives the intended per-tab, ephemeral scope: each tab gets its own True-Name, and it survives a refresh but not closing the tab. It is a bearer credential for the UI state it points to. It does not represent user authentication; application-level auth is separate and layered on top. Rotation and binding are specified later on.

The True-Name is more than an identifier: the client's copy is a secret, and the server stores only a hash of it for lookup, the way passwords are handled. Whenever an Imp is serialized (eviction, checkpoint, migration, or every render in the stateless mode) the blob is sealed with authenticated encryption under a key derived from two halves: the client's True-Name and a server-side key.

This makes the True-Name a capability. Neither half alone opens a stored Imp: a leaked server key yields nothing without the client's True-Name, and unprivileged access to the session store yields only ciphertext. The server holds the derived key in memory only while a session is live; idle and stored state is unreadable to everyone, including the operator.

The lifecycle falls out of the cryptography. When the tab closes and the True-Name is gone, every stored blob for that Imp is permanently unreadable by deletion of the key. Ephemeral is not a policy here, it is enforced. Server-initiated work on an idle session is still possible because a client with a closed stream polls the control endpoint on a fixed interval; wakeup details ride back on that poll, and the client re-presents its True-Name so the server can unseal and act.

This protects idle and stored state. A live, connected session's Imp is necessarily plaintext in server memory; that is what the isolation and containment measures in the server-side security section defend.

### The practical scheme

Sessions are ephemeral, so simplicity is a virtue. The whole scheme is one secret and two derivations.

On first visit the client generates the secret and keeps it in `sessionStorage`:

```php
// illustrative only
$true_name = random_bytes(32);
```

On every request the True-Name travels whole, in a TLS header, never in a URL and never in a log. Sending the secret whole is the same posture as every session cookie on the web; anything fancier defends only against an attacker who already reads TLS or server memory.

On arrival the server derives two values, held in RAM only:

```php
// illustrative only
$id   = sha256($true_name);
$seal = hkdf($true_name, $server_key, 'impui-seal-v1');
```

`$id` is the session's public name, used for routing, storage keys, logs and metrics. `$seal` encrypts stored Imps, with `$id` and a format version bound in as associated data (HKDF is the standard key derivation function from RFC 5869). Nothing persistent or loggable can reconstruct the True-Name.

The request then resolves as the classic session ID flow, unchanged in shape:

```php
// illustrative only
$imp = live($id);

if ($imp === null) {
	$stored = stored($id);
	if ($stored !== null) {
		$imp = unseal($stored, $seal);
	}
}

if ($imp === null) {
	// subject to onboarding
	$imp = mint($id);
}
```

There is no rotation machinery: a session lives hours, and if a key must change, that is simply a new session.

Two edge cases and one accepted limitation, stated so they are decisions rather than surprises:

Browsers copy `sessionStorage` when a tab is duplicated, so two tabs can present the same True-Name; the server forks on second attach, giving the new stream a copy of the Imp under a fresh key.

Some browsers restore `sessionStorage` when reopening a crashed or closed window, so "dies with the tab" means closed by the user in the normal case, not a cryptographic certainty about browser behavior. And an attacker with write access to the session store cannot forge state, but can restore an older validly sealed blob of the same session; for ephemeral UI state this rollback is accepted rather than defended.

## Content Security Policy (CSP)

We won't delve deep into CSP in here, but let's get this out of the way.

IMPUI currently only offers a convenient eval-based attribute system from Datastar which makes it incompatible with stricter policies. However, it's important to note that the SSR architecture offers unique advantages here.

In the future a stricter mode can be considered for maximum security requirements. It could be a runtime-parsed set of attributes for sending values up.

Such a mode would let pages run under a strict policy which very few interactive frameworks can honestly claim. Even without a strict policy, the architecture itself carries security properties that client-heavy frameworks cannot offer.

Because all state lives on the server and only the rendered UI is sent down, data that is not displayed simply does not exist and cannot be interacted with. There is no data API to query, anything injected into the page is confined to what this user can currently see and do.

The server also observes every interaction, and it knows exactly what UI it rendered for each session. A scripted or forged client is loud in this architecture, and that signal can feed threat detection.

The server render is authoritative: patches continuously converge the page toward server truth, so markup-level tampering is transient rather than persistent. This does not defend against actively running scripts, but that is what CSP and a stricter attribute mode are for.

The closest existing discipline to this security model is multiplayer game servers. Games learned early that the client is in the attacker's hands, so the client is only allowed to send inputs and the server simulates and decides everything.

### Server-side security

Everything above buys client-side safety by concentrating state on the server, and that concentration has to be defended. IMPUI moves the trust boundary from "in front of the API" to "around the Imp".

How much defending is needed depends on how you run it, and IMPUI does not prescribe this. The render can run stateless like a typical PHP-FPM request: decrypt the Imp, unserialize it, run, serialize, encrypt, die. Every render starts from a clean process, which is the maximum security posture.

It can run as a persistent process that retains Imps in memory, handling hundreds of thousands of Imp renders per second with minimal overhead. The performance gap is the price of the isolation guarantees below; the security gap is what the rest of this section closes.

In persistent mode, a single server process can hold many Imps in memory at once, so isolation between them is the most important property. Imps never share mutable structures, all mutation goes through a single writer path, and a render is constructed only from the Imp it is addressed to.

A bug that bleeds one Imp into another render is a data breach without any attacker effort, so isolation is enforced by construction rather than by convention.

Every event a client sends is untrusted input arriving at stateful code. The same validation that makes forged clients loud also shrinks this surface: only declared events are accepted, payloads are checked against the rendered UI, and anything else is rejected before application code runs.

The serializable Imp is the sharpest edge. Serialized Imps are stored, migrated and handed between servers, then rehydrated into live state, so they are treated as attacker-adjacent data: a dedicated safe format rather than native language serialization, authenticated so a tampered blob is rejected before parsing, and versioned. Server-to-server handoff is mutually authenticated; an endpoint that accepts Imps from anyone is an endpoint for injecting arbitrary state.

Stateful servers hold load instead of shedding it, so resource exhaustion is a first-class threat. Sessions are cheap to request and expensive to hold, which is why the control endpoint gates the expensive part: Imp size caps, creation limits driven by threat score, event rate limits, and idle eviction to cold storage. The static first render helps here too, since a bot that never completes onboarding never costs a session.

Statefulness also changes operations. A stateless fleet can be killed and redeployed at will; an IMPUI server holds live sessions, so the migration mechanism is what allows a node to be drained, patched and rejoined quickly. Session migration is not only a resilience feature, it is what keeps security patch latency low on a stateful fleet.

The sealed-at-rest scheme concentrates all value in server memory, so memory hygiene is part of the spec, not an ops nicety. Derived sealing keys and plaintext Imps are zeroized on eviction rather than left for the garbage collector, swap is disabled or encrypted on hosts running the sealed profile, and core dumps are disabled. Without these, "idle state is sealed" is undermined by a swap file or crash dump full of plaintext Imps.

Finally, the honest inverse of the client-side story: a compromised IMPUI server exposes the UI state of every session it holds. The mitigations are containment, not prevention. Imps hold UI state, not secrets; credentials live in the application layer and are referenced, never embedded. Segmentation by region and process bounds what any single node exposes, and bounded session lifetimes bound what is in memory at any moment.

## Establishing a link

Every IMPUI server has a default region, and more can be added. Since the entire UI is rendered on the server, latency matters, so IMPUI is designed to make multi-region deployment straightforward.

Route your site through a front domain such as `example.com`. You may put GeoDNS, anycast or similar in front if you like. A configured IMPUI server handles the rest through the control endpoint.

### Control endpoint

Every IMPUI server exposes a control endpoint. It is the second thing the client contacts after loading your page, and it handles onboarding, session tracking, latency metrics and related duties.

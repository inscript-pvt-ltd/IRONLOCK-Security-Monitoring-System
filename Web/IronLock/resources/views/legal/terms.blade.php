@extends('legal.layout')

@php
    $title = 'Terms & Conditions';
    $effective = '1 July 2026';
    $updated = date('j F Y');
@endphp

@section('body')
    <p class="legal-intro">
        These Terms explain the rules for using <strong>IronLock</strong> — both the admin dashboard and
        the guard mobile app. By signing in and using IronLock, you agree to follow them. IronLock is
        for use by a licensed UK security firm and the people it authorises. We've kept the language as
        plain as we can; if anything is unclear, please ask your administrator.
    </p>

    <h2>1. A Few Words We Use</h2>
    <ul>
        <li><strong>The Firm</strong> — the security company that runs IronLock.</li>
        <li><strong>Administrator</strong> — a person allowed to use the web dashboard.</li>
        <li><strong>Guard</strong> — a security officer given an account and using the mobile app.</li>
        <li><strong>The Platform</strong> — IronLock as a whole (the dashboard and the app together).</li>
    </ul>

    <h2>2. Accounts &amp; Signing In</h2>
    <ul>
        <li><strong>Accounts are created for you — no one signs themselves up.</strong> Guard accounts are set up by an administrator. Administrator accounts are set up directly by the technical support team.</li>
        <li><strong>Guards sign in during their shift only.</strong> Trying to log in outside your shift time won't work, and the attempt is recorded.</li>
        <li><strong>One device at a time.</strong> A guard can only be signed in on one device — a new sign-in logs out the old one.</li>
        <li><strong>Too many wrong passwords pauses sign-in.</strong> After several failed attempts in a row, sign-in is temporarily blocked for security — just wait a short while and try again.</li>
        <li>Keep your password private and don't share your account or device with anyone.</li>
    </ul>

    <h2>3. Using It Properly</h2>
    <p>When using IronLock, you agree not to:</p>
    <ul>
        <li>Try to trick, block or get around any of the checks — the GPS tracking, photo checks or welfare checks.</li>
        <li>Fake your attendance, send old or reused photos, or change your device clock to fake a time.</li>
        <li>Try to change or delete any record after it's been logged.</li>
        <li>Try to see or use anything outside what your role allows.</li>
        <li>Use the Platform for anything unlawful or against the Firm's rules.</li>
    </ul>

    <div class="legal-note">
        <strong>The checks are the whole point of IronLock.</strong> The system uses secure, one-time
        codes and its own trusted clock to prove a photo or check is genuine and live. Any attempt to
        get around these is recorded in the permanent log and may be treated as misconduct.
    </div>

    <h2>4. What Guards Agree To</h2>
    <p>By using the app during a shift, a guard agrees to:</p>
    <ul>
        <li>Share their location continuously while on shift.</li>
        <li>Answer a welfare code check within <strong>60 seconds</strong>.</li>
        <li>Take a live photo within <strong>90 seconds</strong> when asked, using the app camera (you can't pick an old photo from the gallery — that's by design).</li>
        <li>Start and end shifts through the app.</li>
    </ul>
    <p>
        Guards understand that not answering a welfare check triggers an immediate alert to a
        supervisor, who may send someone to check on them at their last known location.
    </p>

    <h2>5. What Administrators Are Responsible For</h2>
    <ul>
        <li>Setting up guards, sites and boundaries accurately.</li>
        <li>Scheduling shifts fairly and within the <strong>Working Time Regulations</strong> — IronLock blocks shifts over 16 hours, and warns about shifts over 12 hours or with less than 11 hours' rest. If you override a warning, the reason is recorded.</li>
        <li>Checking a guard's SIA licence is genuine at sign-up — IronLock's 30-day expiry reminder is a helpful nudge, not a replacement for checking.</li>
        <li>Reviewing alerts, noting what was done, and handling photos responsibly.</li>
    </ul>

    <h2>6. Your Privacy</h2>
    <p>
        How we handle personal data is covered in our <a href="{{ route('legal.privacy') }}">Privacy
        Policy</a>, which is part of these Terms. In short: all data stays in the UK, we follow UK data
        protection law, and photos are used only to verify attendance — never to judge performance and
        never shared with outsiders.
    </p>

    <h2>7. Staying Within the Rules</h2>
    <p>IronLock is built to help the Firm meet its obligations under UK rules, including:</p>
    <ul>
        <li>UK data protection law (UK GDPR and the Data Protection Act 2018);</li>
        <li>the Working Time Regulations 1998 (working hours and rest);</li>
        <li>BS 8484:2016 (the standard for lone worker services);</li>
        <li>the rules of the Security Industry Authority (SIA); and</li>
        <li>health and safety law.</li>
    </ul>
    <p>
        The Firm remains responsible for its own scheduling, licensing and compliance. IronLock's
        automatic checks are there to help — except where the system enforces a hard rule, such as
        blocking any shift longer than 16 hours.
    </p>

    <h2>8. When It's Available</h2>
    <p>
        IronLock is built to keep working even when the signal drops — photo and welfare checks are
        saved on the phone and sent up safely once the connection is back, so nothing is lost. Live
        location and the real-time dashboard do need a connection. We can't promise the Platform will
        never have an interruption, and we're not responsible for outages caused by networks, devices or
        other services outside our control.
    </p>

    <h2>9. Ownership</h2>
    <p>
        The IronLock software, design and branding belong to their owners. You may not copy, change,
        take apart or resell any part of it unless we've agreed to it in writing.
    </p>

    <h2>10. Our Responsibility</h2>
    <p>
        IronLock is a monitoring and record-keeping tool. It works best as a deterrent and as a source
        of reliable records — it doesn't replace good security judgement or a supervisor's
        responsibility. As far as the law allows, we're not liable for indirect or knock-on losses from
        using it. Nothing here removes any responsibility that the law says can't be removed.
    </p>

    <h2>11. Suspending or Ending Access</h2>
    <p>
        Access can be paused or stopped if these Terms are broken, if an account is compromised, or if
        the Firm asks for it. Ending access doesn't affect records we're required to keep for compliance.
    </p>

    <h2>12. Changes to These Terms</h2>
    <p>
        We may update these Terms now and then. If you keep using IronLock after a change, that means you
        accept the new version. The "Last updated" date at the top shows the latest change.
    </p>

    <h2>13. Which Law Applies</h2>
    <p>
        These Terms follow the laws of England and Wales, and any disputes are handled by the courts of
        England and Wales.
    </p>

    <h2>14. Contact</h2>
    <p>
        Any questions about these Terms? Please get in touch through your usual administrator.
    </p>
@endsection

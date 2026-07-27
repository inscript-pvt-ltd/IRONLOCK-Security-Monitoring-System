@extends('legal.layout')

@php
    $title = 'Terms & Conditions';
    $effective = '1 July 2026';
    $updated = '27 July 2026';
@endphp

@section('body')
    <p class="legal-intro">
        These Terms explain the rules for using <strong>IronLock</strong> — both the admin dashboard and
        the guard mobile app. By installing, signing into or using IronLock, you agree to follow them.
        If you don't agree with them, don't use IronLock. The Platform is for use by a licensed UK
        security firm and the people it authorises. We've kept the language as plain as we can; if
        anything is unclear, please ask your administrator.
    </p>

    <h2>1. A Few Words We Use</h2>
    <ul>
        <li><strong>The Firm</strong> — the security company that runs IronLock.</li>
        <li><strong>Administrator</strong> — a person allowed to use the web dashboard.</li>
        <li><strong>Guard</strong> — a security officer given an account and using the mobile app.</li>
        <li><strong>The Platform</strong> — IronLock as a whole (the dashboard and the app together).</li>
    </ul>

    <h2>2. Who Can Use IronLock</h2>
    <p>You may use the Platform only if all of the following are true:</p>
    <ul>
        <li>Your employer or organisation has issued you an authorised account.</li>
        <li>You are acting within the role that account was given to you for.</li>
        <li>You follow the law and your organisation's own policies.</li>
    </ul>
    <p>
        <strong>IronLock is not open to public registration.</strong> Downloading the app does not give
        you access — without an account issued by an authorised firm, there is nothing to sign into.
    </p>

    <h2>3. Accounts &amp; Signing In</h2>
    <ul>
        <li><strong>Accounts are created for you — no one signs themselves up.</strong> Guard accounts are set up by an administrator. Administrator accounts are set up directly by the technical support team.</li>
        <li><strong>Guards sign in during their shift only.</strong> Trying to log in outside your shift time won't work, and the attempt is recorded.</li>
        <li><strong>One device at a time.</strong> A guard can only be signed in on one device — a new sign-in logs out the old one.</li>
        <li><strong>Too many wrong passwords pauses sign-in.</strong> After several failed attempts in a row, sign-in is temporarily blocked for security — just wait a short while and try again.</li>
        <li>Keep your password private, keep your device secure, and don't share your account with anyone or use anyone else's.</li>
        <li>Tell your administrator straight away if you think someone else has got into your account.</li>
    </ul>

    <h2>4. Using It Properly</h2>
    <p>When using IronLock, you agree not to:</p>
    <ul>
        <li>Try to trick, block or get around any of the checks — the GPS tracking, photo checks or welfare checks.</li>
        <li>Fake your attendance, send old or reused photos, or change your device clock to fake a time.</li>
        <li>Try to change or delete any record after it's been logged.</li>
        <li>Try to see or use anything outside what your role allows.</li>
        <li>Take the software apart, copy it, or modify it.</li>
        <li>Upload anything harmful, or interfere with how the Platform runs.</li>
        <li>Use the Platform for anything unlawful or against the Firm's rules.</li>
    </ul>

    <div class="legal-note">
        <strong>The checks are the whole point of IronLock.</strong> The system uses secure, one-time
        codes and its own trusted clock to prove a photo or check is genuine and live. Any attempt to
        get around these is recorded in the permanent log and may be treated as misconduct.
    </div>

    <h2>5. Permissions the App Needs</h2>
    <p>To do its job, the mobile app asks your device for access to:</p>
    <ul>
        <li><strong>Location</strong> — while you're signed into an active shift.</li>
        <li><strong>Camera</strong> — to take live verification photos when asked.</li>
        <li><strong>Notifications</strong> — to deliver checks, reminders and alerts.</li>
        <li><strong>Internet access</strong> — to send shift data to the dashboard.</li>
    </ul>
    <p>
        These are used only for the operational purposes described in our
        <a href="{{ route('legal.privacy') }}">Privacy Policy</a>. You can turn any of them off in your
        device settings — but the matching check will then stop working, which may be recorded as a
        missed check and reported to your supervisor.
    </p>

    <h2>6. Location While on Shift</h2>
    <p>
        The app shares your location <strong>while you are signed into an active shift</strong>, to
        confirm you are within your assigned area, support operational monitoring, and give a supervisor
        a last known position if you need help. The app is <strong>not</strong> designed to track anyone
        outside authorised working hours, and it does not keep a history of where you have been.
    </p>

    <h2>7. What Guards Agree To</h2>
    <p>By using the app during a shift, a guard agrees to:</p>
    <ul>
        <li>Share their location continuously while on shift.</li>
        <li>Answer a welfare code check within <strong>60 seconds</strong>.</li>
        <li>Take a live photo when asked, using the app camera — within <strong>90 seconds</strong> of the request when online, or at the scheduled time when the phone is offline. You can't pick an old photo from the gallery; that's by design.</li>
        <li>Start and end shifts through the app.</li>
    </ul>
    <p>
        Guards understand that not answering a welfare check triggers an immediate alert to a
        supervisor, who may send someone to check on them at their last known location.
    </p>

    <h2>8. Which Checks Are Switched On</h2>
    <p>
        Photo checks and welfare checks are configured <strong>per site</strong> by the Firm. Depending
        on the site you're working, one or both may be switched off — in which case the app simply
        won't ask for them during that shift. What is switched on is decided by the Firm, not by the
        guard.
    </p>

    <h2>9. Notifications</h2>
    <p>
        IronLock sends notifications for welfare checks, photo requests, shift activity, emergency
        alerts and important service messages. You can turn notifications off in your device settings,
        but doing so will stop you receiving checks in time and may cause them to be recorded as missed.
    </p>

    <h2>10. What Administrators Are Responsible For</h2>
    <ul>
        <li>Setting up guards, sites and boundaries accurately.</li>
        <li>Scheduling shifts fairly and within the <strong>Working Time Regulations</strong> — IronLock blocks shifts over 16 hours, and warns about shifts over 12 hours or with less than 11 hours' rest. If you override a warning, the reason is recorded.</li>
        <li>Checking a guard's SIA licence is genuine at sign-up — IronLock's 30-day expiry reminder is a helpful nudge, not a replacement for checking.</li>
        <li>Reviewing alerts, noting what was done, and handling photos responsibly.</li>
    </ul>

    <h2>11. Your Privacy</h2>
    <p>
        How we handle personal data is covered in our <a href="{{ route('legal.privacy') }}">Privacy
        Policy</a>, which forms part of these Terms. In short: all data stays in the UK, we follow UK
        data protection law, and photos are used only to verify attendance — never to judge performance
        and never shared with outsiders.
    </p>

    <h2>12. Records Are Permanent</h2>
    <p>
        Once a shift event, alert, photo or check is logged, it becomes part of an unchangeable record —
        nobody, including an administrator, can edit or quietly remove it. Removing a guard from the
        roster does not wipe their history either: the past record keeps naming the person who was
        actually on duty. This is what makes the record trustworthy, and the
        <a href="{{ route('legal.privacy') }}">Privacy Policy</a> explains it in full.
    </p>

    <h2>13. Staying Within the Rules</h2>
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

    <h2>14. When It's Available</h2>
    <p>
        IronLock is built to keep working even when the signal drops — photo and welfare checks are
        saved on the phone and sent up safely once the connection is back, so nothing is lost. Live
        location and the real-time dashboard do need a connection. We can't promise the Platform will
        never have an interruption, and we're not responsible for outages caused by networks, devices,
        scheduled maintenance, device compatibility or other services outside our reasonable control.
    </p>

    <h2>15. App Updates</h2>
    <p>
        We release updates to improve reliability, security and compatibility. Some updates are
        required to keep using the app — if you don't install them, parts of the app may stop working
        correctly or stop working at all.
    </p>

    <h2>16. Ownership</h2>
    <p>
        The IronLock software, source code, design, interface, logos and branding belong to their
        owners and are protected by intellectual property law. You may not copy, change, take apart,
        reverse engineer, distribute or resell any part of it without written permission.
    </p>

    <h2>17. Our Responsibility</h2>
    <p>
        IronLock is a monitoring and record-keeping tool. It works best as a deterrent and as a source
        of reliable records — it doesn't replace good security judgement or a supervisor's
        responsibility. As far as the law allows, we're not liable for indirect or knock-on losses from
        using it, including lost business, network or device failures, or data lost through
        circumstances outside our reasonable control. Nothing here removes any responsibility that the
        law says can't be removed.
    </p>

    <h2>18. Suspending or Ending Access</h2>
    <p>
        Access can be paused or stopped if these Terms are broken, if an account is compromised or shows
        suspicious activity, if your employment or authorised access ends, or if the Firm asks for it.
        Ending access doesn't affect records we're required to keep for compliance — those stay in the
        permanent record.
    </p>

    <h2>19. Changes to These Terms</h2>
    <p>
        We may update these Terms now and then. If you keep using IronLock after a change, that means you
        accept the new version. The "Last updated" date at the top shows the latest change.
    </p>

    <h2>20. Which Law Applies</h2>
    <p>
        These Terms follow the laws of England and Wales, and any disputes are handled by the courts of
        England and Wales, unless the law requires otherwise.
    </p>

    <h2>21. Contact</h2>
    <p>
        Any questions about these Terms or the IronLock mobile app? Please get in touch through your
        usual administrator, or the organisation that provides your IronLock service.
    </p>

    <p>
        By using IronLock, you confirm you have read, understood and agreed to these Terms &amp;
        Conditions.
    </p>
@endsection

@extends('legal.layout')

@php
    $title = 'Privacy Policy';
    $effective = '1 July 2026';
    $updated = '27 July 2026';
@endphp

@section('body')
    <p class="legal-intro">
        This page explains, in plain terms, what information <strong>IronLock</strong> collects about
        security guards, why we collect it, and how we keep it safe. It covers both the
        <strong>IronLock mobile app</strong> used by guards and the <strong>IronLock admin
        dashboard</strong> used by supervisors. IronLock is used only in the United Kingdom, and we
        follow UK data protection law — the <strong>UK GDPR</strong> and the <strong>Data Protection
        Act 2018</strong>. If anything here is unclear, please ask your administrator and we'll be
        happy to explain.
    </p>

    <h2>1. Who This Applies To</h2>
    <p>
        IronLock is not a public app. Accounts are created only by an authorised security firm, and
        there is no public sign-up. This policy applies to:
    </p>
    <ul>
        <li><strong>Guards</strong> — security officers given an account and using the mobile app during their shifts.</li>
        <li><strong>Administrators</strong> — the people who run the web dashboard for the firm.</li>
    </ul>
    <p>
        By using the IronLock app, you acknowledge that your information may be handled as described
        here.
    </p>

    <h2>2. Who Looks After Your Data</h2>
    <p>Two parties are involved in handling data:</p>
    <ul>
        <li>The <strong>security firm</strong> that runs IronLock decides what data is collected and why. In legal terms this makes them the <strong>"Data Controller."</strong></li>
        <li>The <strong>team that built and hosts IronLock</strong> only handles data by following the firm's instructions. In legal terms this makes them the <strong>"Data Processor."</strong></li>
    </ul>

    <h2>3. Why We Collect Data</h2>
    <p>
        We collect data for one simple reason: to make sure guards are <strong>safe, present, and
        alert</strong> during their shift, and to keep an honest record of what happened. The law calls
        this a <strong>"legitimate interest"</strong> — a fair and reasonable business need.
    </p>
    <p>
        Just as important is what we <em>don't</em> do: we only collect location and photos
        <strong>while a guard is actually on shift</strong>. We never track anyone in their own time.
    </p>

    <h2>4. What We Collect</h2>
    <table>
        <thead>
            <tr><th>Type of information</th><th>What it is</th><th>Why we need it</th></tr>
        </thead>
        <tbody>
            <tr>
                <td>Account details</td>
                <td>Name, staff/employee code, username, email, phone, and SIA licence details</td>
                <td>To set up and manage a guard's account and check their licence is valid</td>
            </tr>
            <tr>
                <td>Current location</td>
                <td>Where the guard is right now, updated regularly while on shift</td>
                <td>To check the guard is inside their assigned area and to alert a supervisor if they leave it</td>
            </tr>
            <tr>
                <td>Verification photos</td>
                <td>Live photos taken through the app camera, with the time and place they were taken</td>
                <td>To confirm the guard is really at the site</td>
            </tr>
            <tr>
                <td>Welfare checks</td>
                <td>Whether the guard answered a timed code check, and how quickly</td>
                <td>To confirm the guard is awake and okay</td>
            </tr>
            <tr>
                <td>Shift records</td>
                <td>Check-in, start and end times, early-end requests and their reasons, and shift events</td>
                <td>To keep an accurate attendance and compliance record</td>
            </tr>
            <tr>
                <td>Security records</td>
                <td>Login attempts (with time), session details, and the device name/identifier of the phone signed in</td>
                <td>To keep accounts secure, enforce one-device sign-in, and maintain a trustworthy record</td>
            </tr>
            <tr>
                <td>Push notification token</td>
                <td>An anonymous device token issued by Apple or Google</td>
                <td>To deliver photo requests, welfare checks and alerts to the right phone</td>
            </tr>
        </tbody>
    </table>

    <div class="legal-note">
        <strong>We keep a guard's location for "now" only — not a history.</strong> IronLock stores
        just where a guard is at this moment, and it's replaced every time a new position comes in.
        There is <strong>no map of everywhere they've been</strong> during the shift. The system can
        show where a guard is now — not where they walked earlier.
    </div>

    <h2>5. What We Never Collect</h2>
    <ul>
        <li><strong>No tracking off-shift</strong> — location is only recorded while a guard is working.</li>
        <li><strong>No sound or voice recording</strong> of any kind, and no reading of calls or messages.</li>
        <li><strong>No fingerprints or face scans</strong> — no biometric data, and no facial recognition on verification photos.</li>
        <li><strong>No location history</strong> or route trail.</li>
        <li><strong>No access to your contacts</strong>, photo gallery, or files.</li>
        <li><strong>No selling of personal information</strong> — ever, to anyone.</li>
    </ul>

    <h2>6. What the App Asks Permission For</h2>
    <p>The mobile app asks for these device permissions, and uses them only for the purposes below:</p>
    <ul>
        <li><strong>Location</strong> — to confirm a guard is inside their assigned site boundary while on shift, and to give a supervisor a last known position if something goes wrong.</li>
        <li><strong>Camera</strong> — to take live verification photos when the system asks for one. The app camera is used deliberately so an old picture can't be chosen from the gallery.</li>
        <li><strong>Notifications</strong> — to deliver welfare checks, photo requests, shift reminders and emergency alerts.</li>
        <li><strong>Internet access</strong> — to send shift data to the dashboard.</li>
    </ul>
    <p>
        You can turn any of these off in your device settings, but doing so will stop the matching
        check from working and may be recorded as a missed check.
    </p>

    <h2>7. How We Use the Information</h2>
    <p>The information is used to:</p>
    <ul>
        <li>Sign in authorised users and keep accounts secure.</li>
        <li>Check that a guard is inside their assigned area.</li>
        <li>Confirm, with a live photo, that a guard is really at the site.</li>
        <li>Confirm, with a quick code check, that a guard is awake and responsive.</li>
        <li>Record attendance and shift activity, and raise alerts to a supervisor when something needs attention.</li>
        <li>Produce compliance reports for the security firm.</li>
    </ul>
    <p>
        Photos are used <strong>only to verify shift attendance</strong>. They are never shared with
        outside parties and are never used to judge job performance.
    </p>

    <h2>8. Our Legal Basis</h2>
    <p>Under UK GDPR, we rely on one or more of the following:</p>
    <ul>
        <li><strong>Legitimate interests</strong> — keeping lone workers safe and keeping honest security records.</li>
        <li><strong>Performance of a contract</strong> — your employment or service agreement with the firm.</li>
        <li><strong>Legal obligation</strong> — records the firm is required to keep.</li>
        <li><strong>Vital interests</strong> — where someone's safety is at risk and a supervisor needs a last known location.</li>
    </ul>

    <h2>9. Who We Share It With</h2>
    <p>Personal information is only ever available to:</p>
    <ul>
        <li>Your employer — the security firm running IronLock.</li>
        <li>The administrators that firm authorises to use the dashboard.</li>
        <li>The service providers that keep IronLock running: UK-based cloud hosting, and the push notification services that reach your phone — Google's for <strong>Android</strong> devices, and Google's passing the message to Apple's for <strong>iPhone</strong> devices.</li>
    </ul>
    <p>
        Push notification services carry only a short prompt — never the contents of your records. We
        do not sell, rent, or trade personal information, and we do not use it for advertising.
    </p>

    <h2>10. How We Keep It Safe</h2>
    <ul>
        <li><strong>Everything stays in the UK</strong> — the database, servers and photos are all hosted on UK-based systems. Personal data never leaves the UK.</li>
        <li><strong>Protected while sending</strong> — all data travels over a secure, encrypted (HTTPS) connection.</li>
        <li><strong>Protected while stored</strong> — data and photos are encrypted where they're kept.</li>
        <li><strong>Photos are private</strong> — they're held in private storage and only ever served to a signed-in administrator, never from a public web address.</li>
        <li><strong>Passwords are scrambled</strong> — stored using strong one-way hashing, never as plain text.</li>
        <li><strong>Access is by role</strong> — an account can only reach what its role allows, and access is logged.</li>
        <li><strong>Records can't be edited</strong> — once an event is logged it can't be changed or deleted, so the record stays honest.</li>
        <li><strong>Just for notifications</strong> — with the help of a Google service, we deliver alerts and reminders to the guard's app on both <strong>Android and iPhone</strong> (on an iPhone that service hands the message to Apple to deliver), without sharing any personal details.</li>
    </ul>
    <p>
        We use industry-standard protections, but no system that sends or stores data electronically
        can be promised to be perfectly secure.
    </p>

    <h2>11. When the Signal Drops</h2>
    <p>
        If a guard loses connection, the app keeps working: photos and welfare answers are held
        <strong>on the phone</strong> and sent up once the connection returns. Anything held on the
        phone is stored in the app's own private storage and is only used to complete the shift record.
        Once it has been safely received, the dashboard clearly marks it as having happened while
        offline, with the time it actually happened — not the time it arrived.
    </p>

    <h2>12. How Long We Keep It</h2>
    <ul>
        <li><strong>Shift history and events</strong> — kept as a permanent, unchangeable compliance record. These are never automatically deleted.</li>
        <li><strong>Verification photos</strong> — kept as part of the security record. After each calendar month ends, an administrator can download that month's photos as a backup file (a ZIP), and that backup file is available for <strong>30 days</strong>.</li>
        <li><strong>Generated reports</strong> — the exported PDF/CSV files are cleared after <strong>12 months</strong> by default; they can be regenerated at any time from the underlying records.</li>
        <li><strong>Alerts</strong> — kept indefinitely unless the firm sets its own limit. Alerts that are still open are never removed automatically.</li>
    </ul>
    <p>
        The security firm, as Data Controller, sets these periods and may keep records for longer where
        the law or a contract requires it.
    </p>

    <h2>13. What Happens When a Guard Is Removed</h2>
    <div class="legal-note">
        <strong>Removing a guard hides them from the live system — it does not erase their data.</strong>
        When an administrator removes a guard, the account is deactivated, every signed-in device is
        signed out immediately, and the guard disappears from the roster, the shift picker and licence
        checks. Their details are <strong>kept exactly as they were</strong> so that past shifts,
        alerts, photos and reports still show who was genuinely on duty. The removal can be undone.
    </div>
    <p>
        This is deliberate. A security record that quietly renamed or blanked the person it refers to
        would no longer be a truthful record, and the firm is required to be able to show who was
        working, where, and when.
    </p>
    <p>
        It also means that removing a guard from the roster is <strong>not, by itself, an erasure of
        their personal data</strong>. If someone asks for their personal data to be erased, the
        security firm handles that as a separate request and decides, record by record, what can be
        deleted and what must be kept for legal or contractual reasons — then tells the person the
        outcome.
    </p>

    <h2>14. Your Rights</h2>
    <p>Under UK law, you can ask to:</p>
    <ul>
        <li>See the information held about you.</li>
        <li>Correct anything that's wrong.</li>
        <li>Have your personal information deleted, where the firm isn't required to keep it.</li>
        <li>Object to or limit how your data is used, in certain cases.</li>
        <li>Get a copy of your data to take elsewhere, where that applies.</li>
    </ul>
    <p>
        Requests should go to your employer or system administrator, who will respond within the time
        limits set by UK law. Some records must be retained to meet legal and compliance obligations,
        and where that applies you'll be told which ones and why.
    </p>

    <h2>15. Being Open With Guards</h2>
    <p>
        The first time a guard logs into the app, they see a short, plain-English notice explaining what
        is collected and why. The app also clearly tells guards when their location is being recorded
        (only while on shift) and that a photo check may be requested during a shift.
    </p>

    <h2>16. Age</h2>
    <p>
        IronLock is a professional tool for licensed security personnel and is not intended for anyone
        under 18. We do not knowingly collect information from children.
    </p>

    <h2>17. Data Leaving the UK</h2>
    <p>
        IronLock is built for firms operating in the United Kingdom, and personal data is hosted in the
        UK. If that ever needs to change, appropriate safeguards required by UK data protection law
        will be put in place first, and this policy will be updated.
    </p>

    <h2>18. Questions or Concerns</h2>
    <p>
        The security firm registers its data handling with the UK's data protection regulator, the
        <strong>Information Commissioner's Office (ICO)</strong>. If you're ever unhappy with how your
        data has been handled, you can contact the ICO at
        <a href="https://ico.org.uk" target="_blank" rel="noopener">ico.org.uk</a>.
    </p>

    <h2>19. Updates to This Policy</h2>
    <p>
        We may update this policy from time to time as the system or the law changes. When we make an
        important change, the "Last updated" date at the top will change too.
    </p>

    <h2>20. Contact</h2>
    <p>
        If you have any questions about your data or this policy, please get in touch through your usual
        administrator. This policy works alongside our
        <a href="{{ route('legal.terms') }}">Terms &amp; Conditions</a>.
    </p>
@endsection

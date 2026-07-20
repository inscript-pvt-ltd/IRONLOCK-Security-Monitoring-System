@extends('legal.layout')

@php
    $title = 'Privacy Policy';
    $effective = '1 July 2026';
    $updated = date('j F Y');
@endphp

@section('body')
    <p class="legal-intro">
        This page explains, in plain terms, what information <strong>IronLock</strong> collects about
        security guards, why we collect it, and how we keep it safe. IronLock is used only in the
        United Kingdom, and we follow UK data protection law — the <strong>UK GDPR</strong> and the
        <strong>Data Protection Act 2018</strong>. If anything here is unclear, please ask your
        administrator and we'll be happy to explain.
    </p>

    <h2>1. Who Looks After Your Data</h2>
    <p>Two parties are involved in handling data:</p>
    <ul>
        <li>The <strong>security firm</strong> that runs IronLock decides what data is collected and why. In legal terms this makes them the <strong>"Data Controller."</strong></li>
        <li>The <strong>team that built and hosts IronLock</strong> only handles data by following the firm's instructions. In legal terms this makes them the <strong>"Data Processor."</strong></li>
    </ul>

    <h2>2. Why We Collect Data</h2>
    <p>
        We collect data for one simple reason: to make sure guards are <strong>safe, present, and
        alert</strong> during their shift, and to keep an honest record of what happened. The law calls
        this a <strong>"legitimate interest"</strong> — a fair and reasonable business need.
    </p>
    <p>
        Just as important is what we <em>don't</em> do: we only collect location and photos
        <strong>while a guard is actually on shift</strong>. We never track anyone in their own time.
    </p>

    <h2>3. What We Collect</h2>
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
                <td>Where the guard is right now, updated every 15 seconds while on shift</td>
                <td>To check the guard is inside their assigned area and to alert a supervisor if they leave it</td>
            </tr>
            <tr>
                <td>Verification photos</td>
                <td>Live photos taken through the app, with the time and place they were taken</td>
                <td>To confirm the guard is really at the site</td>
            </tr>
            <tr>
                <td>Welfare checks</td>
                <td>Whether the guard answered a timed code check, and how quickly</td>
                <td>To confirm the guard is awake and okay</td>
            </tr>
            <tr>
                <td>Security records</td>
                <td>Login attempts (with time and device), and session details</td>
                <td>To keep accounts secure and maintain a trustworthy record</td>
            </tr>
        </tbody>
    </table>

    <div class="legal-note">
        <strong>We keep a guard's location for "now" only — not a history.</strong> IronLock stores
        just where a guard is at this moment, and it's replaced every time a new position comes in.
        There is <strong>no map of everywhere they've been</strong> during the shift. The system can
        show where a guard is now — not where they walked earlier.
    </div>

    <h2>4. What We Never Collect</h2>
    <ul>
        <li><strong>No tracking off-shift</strong> — location is only recorded while a guard is working.</li>
        <li><strong>No sound or voice recording</strong> of any kind.</li>
        <li><strong>No fingerprints or face scans</strong> (no biometric data).</li>
        <li><strong>No location history</strong> or route trail.</li>
    </ul>

    <h2>5. How We Use the Information</h2>
    <p>The information is used only to do three things:</p>
    <ul>
        <li>Check that a guard is inside their assigned area.</li>
        <li>Confirm, with a live photo, that a guard is really at the site.</li>
        <li>Confirm, with a quick code check, that a guard is awake and responsive.</li>
    </ul>
    <p>
        Photos are used <strong>only to verify shift attendance</strong>. They are never shared with
        outside parties and are never used to judge job performance.
    </p>

    <h2>6. How We Keep It Safe</h2>
    <ul>
        <li><strong>Everything stays in the UK</strong> — the database, servers and photos are all hosted on UK-based systems. Personal data never leaves the UK.</li>
        <li><strong>Protected while sending</strong> — all data travels over a secure, encrypted connection.</li>
        <li><strong>Protected while stored</strong> — data and photos are encrypted where they're kept.</li>
        <li><strong>Photos are private</strong> — they're only viewable by signed-in administrators, through short-lived secure links, never a public web address.</li>
        <li><strong>Passwords are scrambled</strong> — stored using strong one-way hashing, never as plain text.</li>
        <li><strong>Records can't be edited</strong> — once an event is logged it can't be changed or deleted, so the record stays honest.</li>
        <li><strong>Just for notifications</strong> — with the help of a Google service, we deliver alerts and reminders to the guard's app, without sharing any personal details.</li>
    </ul>

    <h2>7. How Long We Keep It</h2>
    <p>
        Shift records, photos and welfare records are kept as part of the security and compliance
        record. After each calendar month ends, an administrator can download that whole month's photos
        as a backup file (a ZIP) for <strong>30 days</strong>.
    </p>

    <h2>8. Your Rights</h2>
    <p>Under UK law, you can ask to:</p>
    <ul>
        <li>See the information held about you.</li>
        <li>Correct anything that's wrong.</li>
        <li>Have your account data deleted (some records must be kept for legal reasons).</li>
        <li>Object to or limit how your data is used, in certain cases.</li>
        <li>Get a copy of your data to take elsewhere, where that applies.</li>
    </ul>
    <p>
        An administrator can <strong>export or delete</strong> a guard's account data on request. When
        data is deleted, personal details are removed while the legally required record is kept in an
        anonymous form. To make a request, speak to the security firm.
    </p>

    <h2>9. Being Open With Guards</h2>
    <p>
        The first time a guard logs into the app, they see a short, plain-English notice explaining what
        is collected and why. The app also clearly tells guards when their location is being recorded
        (only while on shift) and that a photo check may be requested during a shift.
    </p>

    <h2>10. Questions or Concerns</h2>
    <p>
        The security firm registers its data handling with the UK's data protection regulator, the
        <strong>Information Commissioner's Office (ICO)</strong>. If you're ever unhappy with how your
        data has been handled, you can contact the ICO at
        <a href="https://ico.org.uk" target="_blank" rel="noopener">ico.org.uk</a>.
    </p>

    <h2>11. Updates to This Policy</h2>
    <p>
        We may update this policy from time to time as the system or the law changes. When we make an
        important change, the "Last updated" date at the top will change too.
    </p>

    <h2>12. Contact</h2>
    <p>
        If you have any questions about your data or this policy, please get in touch through your usual
        administrator.
    </p>
@endsection

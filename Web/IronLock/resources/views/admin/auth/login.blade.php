<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Smart Guard Monitor - Admin Login</title>
    <style>
        *, *::before, *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        :root {
            --deep-security-blue: #12355B;
            --premium-gold: #D4AF37;
            --soft-gold: #E8C76A;
            --bg-dark: #0F1419;
            --surface-dark: #1A1F26;
            --border-dark: #2A3441;
            --text-primary: #FFFFFF;
            --text-secondary: #B3BCC7;
            --text-muted: #6B7280;
            --error-red: #EF4444;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--bg-dark);
            color: var(--text-primary);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .login-container {
            width: 100%;
            max-width: 440px;
        }

        .branding {
            text-align: center;
            margin-bottom: 32px;
        }

        .logo {
            width: auto;
            height: 45px;
            margin-bottom: 8px;
            display: block;
            margin-left: auto;
            margin-right: auto;
        }

        .app-title {
            font-size: 18px;
            font-weight: bold;
            margin-bottom: 4px;
        }

        .app-subtitle {
            font-size: 12px;
            color: var(--text-muted);
        }

        .login-card {
            background: var(--surface-dark);
            border: 1.5px solid var(--border-dark);
            border-radius: 8px;
            padding: 24px;
        }

        .form-group {
            margin-bottom: 16px;
        }

        .form-label {
            display: block;
            font-size: 10px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: var(--text-secondary);
            margin-bottom: 6px;
        }

        .form-input {
            width: 100%;
            padding: 12px;
            background: var(--bg-dark);
            border: 1.5px solid var(--border-dark);
            border-radius: 4px;
            color: var(--text-primary);
            font-size: 14px;
            transition: border-color 0.2s ease;
        }

        .form-input:focus {
            outline: none;
            border-color: var(--premium-gold);
            box-shadow: 0 0 0 2px rgba(212, 175, 55, 0.1);
        }

        .form-input.error {
            border-color: var(--error-red);
        }

        .btn-primary {
            width: 100%;
            padding: 12px 16px;
            background: var(--deep-security-blue);
            color: white;
            border: 2px solid var(--deep-security-blue);
            border-radius: 4px;
            font-size: 12px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            cursor: pointer;
            transition: all 0.2s ease;
            margin-top: 8px;
        }

        .btn-primary:hover {
            background: transparent;
            border-color: var(--premium-gold);
            color: var(--premium-gold);
        }

        .btn-primary:disabled {
            background: var(--border-dark);
            border-color: var(--border-dark);
            color: var(--text-muted);
            cursor: not-allowed;
        }

        .error-banner {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid var(--error-red);
            border-radius: 4px;
            padding: 8px 12px;
            font-size: 12px;
            color: var(--error-red);
            margin-top: 12px;
            display: none;
        }

        .error-banner.show {
            display: block;
        }

        .success-banner {
            background: rgba(34, 197, 94, 0.1);
            border: 1px solid #22c55e;
            border-radius: 4px;
            padding: 8px 12px;
            font-size: 12px;
            color: #22c55e;
            margin-top: 12px;
            display: none;
        }

        .success-banner.show {
            display: block;
        }

        .loading-spinner {
            display: inline-block;
            width: 16px;
            height: 16px;
            border: 2px solid transparent;
            border-top: 2px solid currentColor;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin-right: 8px;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        .hidden {
            display: none;
        }

        /* Responsive design */
        @media (max-width: 640px) {
            .login-container {
                max-width: 100%;
            }

            .login-card {
                padding: 20px;
            }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <!-- Branding -->
        <div class="branding">
            <img src="{{ asset('Images/logo/logo.png') }}" alt="IronLock Logo" class="logo">
            <div class="app-title">Smart Guard Monitor</div>
            <div class="app-subtitle">Civil Engineering & Security Portal · UK</div>
        </div>

        <!-- Login Card -->
        <div class="login-card">
            <form id="loginForm" method="POST" action="{{ route('admin.login') }}">
                @csrf

                <div class="form-group">
                    <label for="email" class="form-label">Username</label>
                    <input
                        type="email"
                        id="email"
                        name="email"
                        class="form-input {{ $errors->has('email') ? 'error' : '' }}"
                        value="{{ old('email') }}"
                        placeholder="admin@ironlock.co.uk"
                        required
                        autofocus
                    >
                </div>

                <div class="form-group">
                    <label for="password" class="form-label">Password</label>
                    <input
                        type="password"
                        id="password"
                        name="password"
                        class="form-input {{ $errors->has('password') ? 'error' : '' }}"
                        placeholder="Enter your password"
                        required
                    >
                </div>

                <button type="submit" class="btn-primary" id="submitBtn">
                    <span id="submitText">Sign In</span>
                    <span id="submitLoading" class="hidden">
                        <span class="loading-spinner"></span>Signing In...
                    </span>
                </button>

                <!-- Error Banner -->
                @if ($errors->any())
                    <div class="error-banner show">
                        @foreach ($errors->all() as $error)
                            {{ $error }}
                        @endforeach
                    </div>
                @endif

                <!-- Success Banner -->
                @if (session('success'))
                    <div class="success-banner show">
                        {{ session('success') }}
                    </div>
                @endif
            </form>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('loginForm');
            const submitBtn = document.getElementById('submitBtn');
            const submitText = document.getElementById('submitText');
            const submitLoading = document.getElementById('submitLoading');
            const emailInput = document.getElementById('email');
            const passwordInput = document.getElementById('password');

            // Form validation
            function validateForm() {
                const email = emailInput.value.trim();
                const password = passwordInput.value.trim();

                submitBtn.disabled = !email || !password || password.length < 8;
            }

            // Validate on input
            emailInput.addEventListener('input', validateForm);
            passwordInput.addEventListener('input', validateForm);

            // Initial validation
            validateForm();

            // Form submission
            form.addEventListener('submit', function(e) {
                if (submitBtn.disabled) {
                    e.preventDefault();
                    return;
                }

                // Show loading state
                submitBtn.disabled = true;
                submitText.classList.add('hidden');
                submitLoading.classList.remove('hidden');

                // Hide previous errors
                const errorBanners = document.querySelectorAll('.error-banner');
                errorBanners.forEach(banner => banner.classList.remove('show'));
            });

            // Auto-hide success messages after 3 seconds
            const successBanners = document.querySelectorAll('.success-banner.show');
            successBanners.forEach(banner => {
                setTimeout(() => {
                    banner.classList.remove('show');
                }, 3000);
            });
        });
    </script>
</body>
</html>

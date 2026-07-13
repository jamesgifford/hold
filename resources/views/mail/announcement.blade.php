@php
    /*
     * Hold's single, self-contained announcement / notification email.
     *
     * There is NO dependency on Laravel's mail markdown components or theme —
     * no logo, no build step, no shared layout. Every notification the package
     * sends (launch, restore, receipt, team notice) renders THIS one file via
     * ->view(), passing a $heading and $body. Structural tweaks per message can
     * branch on $context; everything else is edited right here.
     *
     * ── Palette ────────────────────────────────────────────────────────────
     * Edit these five values to match your brand. They are plain PHP variables
     * (NOT CSS custom properties — email clients support those poorly) and are
     * interpolated into the inline styles below.
     */
    $bg     = '#f5f6f8';  // page background
    $card   = '#ffffff';  // card background
    $text   = '#1a1d24';  // body text
    $muted  = '#6b7280';  // secondary / footnote text
    $accent = '#2563eb';  // heading, button, links

    /*
     * Content — supplied by the notification; the defaults keep the template
     * safe to render on its own. $body may be a single string or an array of
     * paragraphs. $actionUrl/$actionText render an optional button; $footnote a
     * small line beneath the card. $context and $signup are available for any
     * per-message branching you want to add.
     */
    $heading    = $heading ?? 'Hello';
    $lines      = is_array($body ?? null) ? $body : array_filter([$body ?? null], fn ($l) => $l !== null && $l !== '');
    $actionUrl  = $actionUrl ?? null;
    $actionText = $actionText ?? 'Visit';
    $footnote   = $footnote ?? null;
    $context    = $context ?? null;
    $signup     = $signup ?? null;
@endphp
<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="color-scheme" content="light">
    <title>{{ $heading }}</title>
</head>
<body style="margin:0; padding:0; width:100%; background:{{ $bg }}; color:{{ $text }}; -webkit-text-size-adjust:100%; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; line-height:1.6;">
    <!-- hold:announcement -->

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:{{ $bg }};">
        <tr>
            <td align="center" style="padding:32px 16px;">
                <table role="presentation" width="520" cellpadding="0" cellspacing="0" border="0" style="width:100%; max-width:520px;">
                    <tr>
                        <td style="background:{{ $card }}; border-radius:12px; padding:40px 40px 32px;">
                            <h1 style="margin:0 0 20px; font-size:24px; line-height:1.3; font-weight:700; color:{{ $accent }};">{{ $heading }}</h1>
                            @foreach ($lines as $line)
                                <p style="margin:0 0 16px; font-size:16px; color:{{ $text }};">{{ $line }}</p>
                            @endforeach
                            @if ($actionUrl)
                                <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:8px 0 4px;">
                                    <tr>
                                        <td align="center" style="border-radius:8px; background:{{ $accent }};">
                                            <a href="{{ $actionUrl }}" target="_blank" rel="noopener" style="display:inline-block; padding:12px 28px; font-size:16px; font-weight:600; line-height:1; color:#ffffff; text-decoration:none; border-radius:8px;">{{ $actionText }}</a>
                                        </td>
                                    </tr>
                                </table>
                            @endif
                        </td>
                    </tr>
                    @if ($footnote)
                        <tr>
                            <td style="padding:20px 40px 0; font-size:13px; line-height:1.5; color:{{ $muted }};">
                                {{ $footnote }}
                            </td>
                        </tr>
                    @endif
                </table>
            </td>
        </tr>
    </table>
</body>
</html>

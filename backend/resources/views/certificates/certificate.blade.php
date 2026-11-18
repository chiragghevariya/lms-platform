{{-- Certificate PDF (dompdf, A4 landscape). Rendered to a file, never served as a page. --}}
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Certificate {{ $certificate->verification_code }}</title>
<style>
    @page { margin: 0; }
    * { font-family: DejaVu Sans, sans-serif; }
    body { margin: 0; color: #1f2937; }
    .frame { position: absolute; top: 28px; left: 28px; right: 28px; bottom: 28px; border: 3px solid #4338ca; }
    .inner { position: absolute; top: 40px; left: 40px; right: 40px; bottom: 40px; border: 1px solid #c7d2fe; text-align: center; }
    .brand { margin-top: 48px; font-size: 22px; font-weight: bold; color: #4338ca; letter-spacing: 2px; }
    .tagline { font-size: 11px; color: #6b7280; letter-spacing: 3px; text-transform: uppercase; }
    .title { margin-top: 36px; font-size: 34px; letter-spacing: 6px; text-transform: uppercase; color: #111827; }
    .presented { margin-top: 26px; font-size: 13px; color: #6b7280; }
    .name { margin-top: 8px; font-size: 40px; font-weight: bold; color: #111827; }
    .rule { width: 360px; margin: 6px auto 0; border-top: 2px solid #10b981; }
    .course-label { margin-top: 22px; font-size: 13px; color: #6b7280; }
    .course { margin-top: 6px; font-size: 22px; font-weight: bold; color: #4338ca; }
    /* dompdf ignores `bottom` on nested absolute boxes, so the footer stays in normal flow. */
    .footer { width: 88%; margin: 120px auto 0; border-collapse: collapse; table-layout: fixed; }
    .footer td { width: 33%; padding: 0 18px; vertical-align: top; text-align: center; font-size: 11px; color: #374151; }
    .sig { border-top: 1px solid #9ca3af; padding-top: 6px; }
    .verify { font-size: 9px; color: #6b7280; word-wrap: break-word; }
</style>
</head>
<body>
<div class="frame"></div>
<div class="inner">
    <div class="brand">{{ $brand['name'] }}</div>
    <div class="tagline">{{ $brand['tagline'] }}</div>
    <div class="title">Certificate of Completion</div>
    <div class="presented">This certifies that</div>
    <div class="name">{{ $certificate->holder_name }}</div>
    <div class="rule"></div>
    <div class="course-label">has successfully completed the course</div>
    <div class="course">{{ $certificate->course_title }}</div>

    <table class="footer">
        <tr>
            <td><div class="sig">{{ $certificate->instructor_name }}<br>Instructor</div></td>
            <td><div class="sig">{{ $certificate->issued_at->format('d F Y') }}<br>Date of issue</div></td>
            <td><div class="sig">ID {{ $certificate->verification_code }}<br><span class="verify">Verify at {{ $verifyUrl }}</span></div></td>
        </tr>
    </table>
</div>
</body>
</html>

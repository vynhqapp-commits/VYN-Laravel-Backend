<!doctype html>
<html>
  <body>
    <p>Hi{{ $inviteeName ? ' '.$inviteeName : '' }},</p>
    <p>You have been invited as a <strong>Franchise Owner</strong> for <strong>{{ $salonName }}</strong>.</p>
    <p>This invitation expires at: {{ optional($expiresAt)->toDateTimeString() }}</p>
    <p>
      Accept your invitation:
      <a href="{{ $inviteUrl }}">{{ $inviteUrl }}</a>
    </p>
  </body>
</html>


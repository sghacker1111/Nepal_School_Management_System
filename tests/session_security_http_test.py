"""Real separate browser sessions: credential changes must revoke old cookies."""
import re, json, time
from http_support import ERPHttpFixture
from routes_http_test import send
from app_response_http_test import HX


def assert_signed_out(app, opener):
    current = app.opener
    app.opener = opener
    try:
        headers, body, _ = send(app, '/account/profile', headers=HX, status=401)
        assert headers['HX-Redirect'] == app.base + '/login'
        assert b'Test Teacher' not in body
    finally:
        app.opener = current


def run():
    with ERPHttpFixture() as app:
        f = app.fixture
        app.login('teacher'); old_browser = app.opener
        app.login('teacher')
        body = app.request('/account/change-password', {
            'old_password': 'TestPassword123!', 'new_password': 'ChangedPassword123!',
            'confirm_password': 'ChangedPassword123!'
        })
        assert 'Password changed successfully!' in body
        app.token = re.search(r'name="csrf-token" content="([^"]+)"', body).group(1)
        app.request('/account/profile')
        assert_signed_out(app, old_browser)
        # The new login remains usable, with CSRF rotated along with the cookie.
        app.request('/account/change-password', {
            'old_password': 'ChangedPassword123!', 'new_password': 'TestPassword123!',
            'confirm_password': 'TestPassword123!'
        })
        app.login('teacher'); reset_browser = app.opener
        app.login('school_admin')
        payload = {'user_id': f['ids']['teacher'], 'username': 'teacher',
                   'full_name': 'Test Teacher', 'role': 'teacher', 'status': '1',
                   'password': 'AdministratorReset123!', 'email': '', 'phone': ''}
        body = app.request('/settings/users', payload)
        assert 'Account saved' in body
        assert_signed_out(app, reset_browser)
        # A role change also revokes an otherwise valid old login.
        app.login('principal'); role_browser = app.opener
        app.login('school_admin')
        app.request('/settings/users', {'user_id': f['ids']['principal'],
            'username': 'principal', 'full_name': 'Test Principal',
            'role': 'exam_officer', 'status': '1', 'password': ''})
        assert_signed_out(app, role_browser)
        # Bad shapes and overlong passwords are handled without fatal errors.
        for data in ({'old_password[]':'x','new_password[]':'x','confirm_password[]':'x'},
                     {'old_password':'TestPassword123!','new_password':'x'*73,'confirm_password':'x'*73}):
            body = app.request('/account/change-password', data)
            assert 'Enter valid passwords' in body
        send(app, '/account/verify-password', status=405)
        app.request('/account/verify-password', {'password[]': 'bad'})
        app.request('/account/verify-password', {'password': 'x'*1025})
        for _ in range(10):
            assert not json.loads(app.request('/account/verify-password', {'password':'WrongPassword123!'}))['success']
        assert not json.loads(app.request('/account/verify-password', {'password':'TestPassword123!'}))['success']
        # The window expires without a schema change or an administrator reset.
        app.db.execute('UPDATE login_attempts SET attempted_at=?', (int(time.time())-901,));app.db.commit()
        assert json.loads(app.request('/account/verify-password', {'password':'TestPassword123!'}))['success']
        # Version details stay inside system administration.
        assert 'this web server reports PHP' not in app.request('/')
        app.login('admin')
        assert 'this web server reports PHP' in app.request('/admin')
        send(app, '/includes/runtime_security.php', status=404)
        print('Session security HTTP passed: self-change, admin reset, role revocation, malformed passwords and private runtime diagnostics.')


if __name__ == '__main__':
    run()

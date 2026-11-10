import time
import json
import base64
import hmac
import hashlib
import subprocess

# Secret key
SECRET = "test-secret-key-32-chars-long-example!!"

# Python HMAC-SHA256 JWT creation
header = {"alg": "HS256", "typ": "JWT"}
payload = {
    "iss": "FastAPI Auth",
    "sub": "student01",
    "uid": 1234,
    "exp": int(time.time()) + 60,
    "wantsurl": "/course/view.php?id=10"
}

def b64url(data: bytes) -> str:
    return base64.urlsafe_b64encode(data).decode('utf-8').rstrip('=')

h_b64 = b64url(json.dumps(header).encode('utf-8'))
p_b64 = b64url(json.dumps(payload).encode('utf-8'))
sig = hmac.new(SECRET.encode('utf-8'), f"{h_b64}.{p_b64}".encode('utf-8'), hashlib.sha256).digest()
sig_b64 = b64url(sig)

ticket = f"{h_b64}.{p_b64}.{sig_b64}"
print(f"Generated Ticket in Python: {ticket}")

# Now verify it with PHP using auth/sadaa/classes/jwt_helper.php
php_code = f"""
define('MOODLE_INTERNAL', true);
class moodle_exception extends Exception {{}}
require_once('auth/sadaa/classes/jwt_helper.php');

try {{
    $payload = \\auth_sadaa\\jwt_helper::verify('{ticket}', '{SECRET}');
    echo "SUCCESS:" . json_encode($payload);
}} catch (Exception $e) {{
    echo "ERROR:" . $e->getMessage();
}}
"""

res = subprocess.run(["php", "-r", php_code], capture_output=True, text=True)
print("PHP output:", res.stdout)
assert "SUCCESS" in res.stdout
assert "student01" in res.stdout
print("Cross-language SSO ticket verification passed 100%!")

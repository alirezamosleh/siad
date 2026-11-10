import asyncio
import sys
from pathlib import Path
from unittest.mock import MagicMock

# Mock pydantic_settings if running on host python without venv
if "pydantic_settings" not in sys.modules:
    mock_pydantic_settings = MagicMock()
    class DummySettings:
        def __init__(self, **kwargs):
            for k, v in kwargs.items():
                setattr(self, k, v)
    mock_pydantic_settings.BaseSettings = DummySettings
    mock_pydantic_settings.SettingsConfigDict = lambda **kwargs: {}
    sys.modules["pydantic_settings"] = mock_pydantic_settings

# Add project root to sys.path
sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

from app.services.sms_service import normalize_iranian_phone, mask_phone_number
from app.services.otp_service import create_and_store_otp, verify_otp_code


def test_phone_normalization():
    assert normalize_iranian_phone("09123456789") == "09123456789"
    assert normalize_iranian_phone("+989123456789") == "09123456789"
    assert normalize_iranian_phone("00989123456789") == "09123456789"
    assert normalize_iranian_phone("989123456789") == "09123456789"
    assert normalize_iranian_phone("9123456789") == "09123456789"
    assert normalize_iranian_phone(" 0912 345 6789 ") == "09123456789"
    assert normalize_iranian_phone("invalid") is None
    assert normalize_iranian_phone("1234567") is None
    print("✓ Phone normalization tests passed.")


def test_phone_masking():
    assert mask_phone_number("09123456789") == "0912***6789"
    assert mask_phone_number("+989351112233") == "0935***2233"
    print("✓ Phone masking tests passed.")


async def test_otp_lifecycle():
    username = "test_student"
    phone = "09123456789"

    # 1. Create OTP
    ok, msg, code, cooldown = await create_and_store_otp(username, 10, phone)
    assert ok is True
    assert len(code) == 5
    assert code.isdigit()
    assert cooldown == 60
    print(f"✓ OTP created: {code}")

    # 2. Cooldown enforcement
    ok_cd, msg_cd, code_cd, rem = await create_and_store_otp(username, 10, phone)
    assert ok_cd is False
    assert rem > 0
    print("✓ Cooldown enforcement verified.")

    # 3. Wrong code attempt
    val, vmsg, _ = await verify_otp_code(username, "00000")
    assert val is False
    assert "تلاش دیگر باقی مانده است" in vmsg
    print("✓ Wrong code correctly rejected.")

    # 4. Correct code verification
    val_ok, vmsg_ok, entry = await verify_otp_code(username, code)
    assert val_ok is True
    assert entry["user_id"] == 10
    assert entry["username"] == username
    print("✓ Correct code successfully verified.")

    # 5. Subsequent attempt fails because code was consumed
    val_again, _, _ = await verify_otp_code(username, code)
    assert val_again is False
    print("✓ Code single-use consumption verified.")


async def main():
    test_phone_normalization()
    test_phone_masking()
    await test_otp_lifecycle()
    print("\nALL OTP UNIT TESTS PASSED SUCCESSFULLY! 🎉")


if __name__ == "__main__":
    asyncio.run(main())

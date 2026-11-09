import { useMutation, useQueryClient } from 'react-query';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { toast } from 'react-toastify';
import { useTranslation } from 'react-i18next';
import axios from '../../../helpers/Axios';
import { useAuth } from '../../../contexts/auth/AuthProvider';

function errorCode(err) {
  const errors = err?.response?.data?.errors;
  if (Array.isArray(errors)) return errors[0];
  return errors;
}

export default function useCreateSession() {
  const { t } = useTranslation();
  const queryClient = useQueryClient();
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const redirect = searchParams.get('location');
  const { setStateChanging } = useAuth();

  return useMutation(
    ({ session, token }) => axios.post('/sessions.json', { session, token }).then((resp) => resp.data.data),
    {
      onSuccess: async (response) => {
        setStateChanging(true);
        await queryClient.refetchQueries('useSessions');

        if (redirect) {
          navigate(redirect);
        } else if (response.permissions.CreateRoom === 'false') {
          navigate('/home');
        } else {
          navigate('/rooms');
        }
        setStateChanging(false);
      },
      onError: (err) => {
        const code = errorCode(err);
        const status = err?.response?.status;

        if (code === 'PendingUser') {
          navigate('/pending');
        } else if (code === 'BannedUser') {
          toast.error(t('toast.error.users.banned'));
        } else if (code === 'UnverifiedUser') {
          navigate(`/verify?id=${err.response.data.data}`);
        } else if (code === 'PasswordNotSet') {
          navigate(`/reset_password/${err.response.data.data}`);
        } else if (code === 'HCaptchaInvalid') {
          toast.error(t('toast.error.users.hcaptcha_invalid'));
        } else if (code === 'InvalidAuthenticityToken' || status === 422) {
          toast.error(t('toast.error.problem_completing_action'));
        } else if (!err?.response || status >= 500 || code === 'SomethingWentWrong') {
          toast.error(t('toast.error.problem_completing_action'));
        } else {
          toast.error(t('toast.error.session.invalid_credentials'));
        }
      },
    },
  );
}

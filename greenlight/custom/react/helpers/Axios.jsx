import axios from 'axios';
import { toast } from 'react-toastify';

const axiosInstance = axios.create({
  baseURL: `${process.env.RELATIVE_URL_ROOT || ''}/api/v1`,
  headers: {
    'Content-Type': 'application/json',
    Accept: 'application/json',
  },
  timeout: 30_000,
  withCredentials: true,
});

function readCsrfToken() {
  return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
    || document.querySelector('[name=csrf-token]')?.getAttribute('content')
    || '';
}

axiosInstance.interceptors.request.use((config) => {
  const token = readCsrfToken();
  if (token) {
    config.headers['X-CSRF-TOKEN'] = token;
  }
  return config;
});

axiosInstance.interceptors.response.use(
  (response) => response,
  (error) => {
    const status = error?.response?.status;
    const errors = error?.response?.data?.errors;
    const code = Array.isArray(errors) ? errors[0] : errors;
    const method = String(error?.config?.method || '').toLowerCase();
    const isWrite = ['post', 'put', 'patch', 'delete'].includes(method);

    const csrfDead = status === 422 || status === 419
      || code === 'InvalidAuthenticityToken'
      || (isWrite && status === 500 && code === 'SomethingWentWrong');

    if (csrfDead && isWrite && !window.__hodaCsrfReload) {
      window.__hodaCsrfReload = true;
      try {
        toast.error('نشست منقضی شد — صفحه تازه می‌شود…');
      } catch (e) { /* ignore */ }
      setTimeout(() => { window.location.reload(); }, 700);
    }

    return Promise.reject(error);
  },
);

export default axiosInstance;

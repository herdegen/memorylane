import { ref } from 'vue';

// File de toasts partagée (module singleton) : n'importe quel composant peut
// pousser une notification, ToastContainer (monté dans AppLayout) les affiche.
const toasts = ref([]);
let nextId = 1;

const DEFAULT_DURATION = 5000;

function push(type, message, { duration = DEFAULT_DURATION } = {}) {
  const id = nextId++;
  toasts.value.push({ id, type, message });
  if (duration > 0) {
    setTimeout(() => dismiss(id), duration);
  }
  return id;
}

function dismiss(id) {
  const index = toasts.value.findIndex((t) => t.id === id);
  if (index !== -1) {
    toasts.value.splice(index, 1);
  }
}

export function useToast() {
  return {
    toasts,
    dismiss,
    success: (message, options) => push('success', message, options),
    error: (message, options) => push('error', message, options),
    info: (message, options) => push('info', message, options),
  };
}

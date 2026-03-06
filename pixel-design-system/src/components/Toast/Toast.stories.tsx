import { useEffect, useRef, useState } from 'react';
import type { Meta, StoryObj } from '@storybook/react';
import { Button } from '../Button/Button';
import { Toast, type ToastVariant } from './Toast';

type ToastPlacement =
  | 'top-left'
  | 'top-center'
  | 'top-right'
  | 'bottom-left'
  | 'bottom-center'
  | 'bottom-right';

interface DemoToast {
  id: number;
  variant: ToastVariant;
  title: string;
  description: string;
  placement: ToastPlacement;
  isLeaving: boolean;
}

const TOAST_EXIT_MS = 200;

const placementOptions: Array<{ value: ToastPlacement; label: string }> = [
  { value: 'top-left', label: 'Top Left' },
  { value: 'top-center', label: 'Top Center' },
  { value: 'top-right', label: 'Top Right' },
  { value: 'bottom-left', label: 'Bottom Left' },
  { value: 'bottom-center', label: 'Bottom Center' },
  { value: 'bottom-right', label: 'Bottom Right' },
];

const placementContainerClasses: Record<ToastPlacement, string> = {
  'top-left': 'top-4 left-4 items-start flex-col',
  'top-center': 'top-4 left-1/2 -translate-x-1/2 items-center flex-col',
  'top-right': 'top-4 right-4 items-end flex-col',
  'bottom-left': 'bottom-4 left-4 items-start flex-col-reverse',
  'bottom-center': 'bottom-4 left-1/2 -translate-x-1/2 items-center flex-col-reverse',
  'bottom-right': 'bottom-4 right-4 items-end flex-col-reverse',
};

const toastCopy: Record<ToastVariant, { title: string; description: string }> = {
  info: {
    title: 'Information',
    description: 'Match lobby opens in 5 minutes.',
  },
  success: {
    title: 'Success',
    description: 'Score reported successfully.',
  },
  warning: {
    title: 'Warning',
    description: 'Player eligibility expires in 48h.',
  },
  error: {
    title: 'Error',
    description: 'Upload failed. Retry in a few seconds.',
  },
};

const meta: Meta<typeof Toast> = {
  title: 'Components/Toast',
  component: Toast,
  argTypes: {
    variant: { control: 'select', options: ['info', 'success', 'warning', 'error'] },
    theme: { control: 'select', options: ['dark', 'light'] },
  },
  args: {
    title: 'Match result saved',
    description: 'OWNED 3 - 1 CRYSTAL',
    variant: 'success',
  },
};

export default meta;
type Story = StoryObj<typeof Toast>;

export const Default: Story = {};

export const Variants: Story = {
  render: (args) => (
    <div className="flex flex-col gap-3 max-w-md">
      <Toast {...args} variant="info" title="Information" description="Next match scheduled for 20H CET." />
      <Toast {...args} variant="success" title="Match result saved" description="OWNED 3 - 1 CRYSTAL" />
      <Toast {...args} variant="warning" title="Warning" description="Player eligibility expires in 48h." />
      <Toast {...args} variant="error" title="Upload failed" description="File exceeds 10MB" />
    </div>
  ),
};

export const WithDismiss: Story = {
  args: {
    onDismiss: () => {},
  },
};

export const StateMatrix: Story = {
  render: (args) => (
    <div className="flex flex-col gap-3 max-w-md">
      <Toast {...args} variant="success" title="With dismiss" onDismiss={() => {}} />
      <Toast {...args} variant="error" title="Without dismiss" />
      <Toast {...args} variant="info" title="No description" description={undefined} />
    </div>
  ),
};

export const PresentationPage: Story = {
  args: { theme: 'dark' },
  parameters: {
    layout: 'fullscreen',
  },
  render: function PresentationPageStory(args) {
    const [placement, setPlacement] = useState<ToastPlacement>('top-right');
    const [toasts, setToasts] = useState<DemoToast[]>([]);
    const toastIdRef = useRef(0);
    const autoTimerMapRef = useRef<Record<number, ReturnType<typeof setTimeout>>>({});
    const exitTimerMapRef = useRef<Record<number, ReturnType<typeof setTimeout>>>({});

    useEffect(() => {
      return () => {
        Object.values(autoTimerMapRef.current).forEach((timer) => clearTimeout(timer));
        Object.values(exitTimerMapRef.current).forEach((timer) => clearTimeout(timer));
      };
    }, []);

    function finalizeToastRemoval(id: number) {
      const autoTimer = autoTimerMapRef.current[id];
      if (autoTimer) {
        clearTimeout(autoTimer);
        delete autoTimerMapRef.current[id];
      }
      const exitTimer = exitTimerMapRef.current[id];
      if (exitTimer) {
        clearTimeout(exitTimer);
        delete exitTimerMapRef.current[id];
      }
      setToasts((current) => current.filter((toast) => toast.id !== id));
    }

    function queueToastRemoval(id: number) {
      if (exitTimerMapRef.current[id]) return;

      const autoTimer = autoTimerMapRef.current[id];
      if (autoTimer) {
        clearTimeout(autoTimer);
        delete autoTimerMapRef.current[id];
      }

      setToasts((current) =>
        current.map((toast) => (toast.id === id ? { ...toast, isLeaving: true } : toast)),
      );

      exitTimerMapRef.current[id] = setTimeout(() => {
        finalizeToastRemoval(id);
      }, TOAST_EXIT_MS);
    }

    function pushToast(variant: ToastVariant) {
      toastIdRef.current += 1;
      const id = toastIdRef.current;
      const content = toastCopy[variant];

      setToasts((current) => [
        {
          id,
          variant,
          title: content.title,
          description: content.description,
          placement,
          isLeaving: false,
        },
        ...current,
      ]);

      autoTimerMapRef.current[id] = setTimeout(() => {
        queueToastRemoval(id);
      }, 4500);
    }

    const groupedToasts: Record<ToastPlacement, DemoToast[]> = {
      'top-left': [],
      'top-center': [],
      'top-right': [],
      'bottom-left': [],
      'bottom-center': [],
      'bottom-right': [],
    };

    toasts.forEach((toast) => {
      groupedToasts[toast.placement].push(toast);
    });

    const panelThemeClasses =
      args.theme === 'light'
        ? 'bg-nm-light border border-black/[0.08] shadow-nm-flat-l text-px-offblack'
        : 'bg-nm-dark border border-white/[0.08] shadow-nm-flat-d text-px-white';

    return (
      <div className="relative min-h-[580px] overflow-hidden p-6">
        <div className={`mb-6 p-5 ${panelThemeClasses}`}>
          <div className="flex flex-col gap-4">
            <div>
              <p className="font-display text-xl font-bold uppercase tracking-display">
                Toast Playground
              </p>
              <p className="font-body text-xs text-px-label mt-1">
                Pattern inspired by Sonner/react-hot-toast: click a button to publish toasts, choose viewport placement.
              </p>
            </div>

            <div className="flex flex-wrap items-center gap-2">
              {placementOptions.map((option) => (
                <Button
                  key={option.value}
                  size="sm"
                  theme={args.theme}
                  variant={placement === option.value ? 'primary' : 'ghost'}
                  onClick={() => setPlacement(option.value)}
                >
                  {option.label}
                </Button>
              ))}
            </div>

            <div className="flex flex-wrap items-center gap-2">
              <Button theme={args.theme} variant="secondary" size="sm" onClick={() => pushToast('info')}>
                Show Info
              </Button>
              <Button theme={args.theme} variant="primary" size="sm" onClick={() => pushToast('success')}>
                Show Success
              </Button>
              <Button theme={args.theme} variant="ghost-primary" size="sm" onClick={() => pushToast('warning')}>
                Show Warning
              </Button>
              <Button theme={args.theme} variant="destructive" size="sm" onClick={() => pushToast('error')}>
                Show Error
              </Button>
              <Button
                theme={args.theme}
                variant="ghost"
                size="sm"
                onClick={() => {
                  toasts.forEach((toast) => queueToastRemoval(toast.id));
                }}
                disabled={toasts.length === 0}
              >
                Clear All
              </Button>
            </div>
          </div>
        </div>

        <div
          className={`relative h-[380px] border border-dashed ${
            args.theme === 'light' ? 'border-black/[0.2] bg-nm-light-s/40' : 'border-white/[0.2] bg-nm-dark-s/40'
          }`}
        >
          {placementOptions.map((option) => (
            <div
              key={option.value}
              className={`absolute z-30 flex w-[min(90vw,360px)] gap-3 ${placementContainerClasses[option.value]}`}
            >
              {groupedToasts[option.value].map((toast) => (
                <div
                  key={toast.id}
                  className={`w-full transition-[opacity,transform] duration-200 ease-out ${
                    toast.isLeaving ? 'opacity-0 translate-y-1 scale-[0.98]' : 'opacity-100 translate-y-0 scale-100'
                  }`}
                >
                  <Toast
                    theme={args.theme}
                    variant={toast.variant}
                    title={toast.title}
                    description={toast.description}
                    onDismiss={() => queueToastRemoval(toast.id)}
                    className="w-full"
                  />
                </div>
              ))}
            </div>
          ))}
        </div>
      </div>
    );
  },
};

export const LightTheme: Story = {
  args: { theme: 'light' },
  parameters: { backgrounds: { default: 'light' } },
};

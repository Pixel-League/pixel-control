import { useState } from 'react';
import type { Meta, StoryObj } from '@storybook/react';
import { Modal, type ModalSize } from './Modal';
import { Button } from '../Button/Button';

const meta: Meta<typeof Modal> = {
  title: 'Components/Modal',
  component: Modal,
  argTypes: {
    size: { control: 'select', options: ['sm', 'md', 'lg'] },
    theme: { control: 'select', options: ['dark', 'light'] },
    open: { control: 'boolean' },
  },
  args: {
    open: true,
    title: 'Confirm Action',
    children: (
      <p className="font-body text-sm text-px-label">
        Are you sure you want to proceed with this action? This cannot be undone.
      </p>
    ),
  },
};

export default meta;
type Story = StoryObj<typeof Modal>;

export const Default: Story = {
  args: {
    onClose: () => {},
    footer: (
      <div className="flex gap-3">
        <Button variant="ghost" size="sm">Cancel</Button>
        <Button variant="primary" size="sm">Confirm</Button>
      </div>
    ),
  },
};

export const WithTrigger: Story = {
  render: function WithTriggerStory(args) {
    const [open, setOpen] = useState(false);
    return (
      <>
        <Button onClick={() => setOpen(true)}>Open Modal</Button>
        <Modal
          {...args}
          open={open}
          onClose={() => setOpen(false)}
          title="Match Details"
          footer={
            <div className="flex gap-3">
              <Button variant="ghost" size="sm" onClick={() => setOpen(false)}>Cancel</Button>
              <Button variant="primary" size="sm" onClick={() => setOpen(false)}>Save</Button>
            </div>
          }
        >
          <p className="font-body text-sm text-px-label">
            OWNED vs CRYSTAL — Semi-final — Best of 5
          </p>
        </Modal>
      </>
    );
  },
};

export const PresentationPage: Story = {
  args: { theme: 'dark' },
  parameters: {
    layout: 'fullscreen',
  },
  render: function PresentationPageStory(args) {
    const [open, setOpen] = useState(false);
    const [size, setSize] = useState<ModalSize>('md');
    const [closeOnOverlayClick, setCloseOnOverlayClick] = useState(true);

    const panelThemeClasses =
      args.theme === 'light'
        ? 'bg-nm-light border border-black/[0.08] shadow-nm-flat-l text-px-offblack'
        : 'bg-nm-dark border border-white/[0.08] shadow-nm-flat-d text-px-white';

    return (
      <div className="p-6 min-h-[520px]">
        <div className={`max-w-3xl p-5 ${panelThemeClasses}`}>
          <div className="space-y-4">
            <div>
              <p className="font-display text-xl font-bold uppercase tracking-display">
                Modal Playground
              </p>
              <p className="font-body text-xs text-px-label mt-1">
                Trigger a modal with configurable size and overlay behavior.
              </p>
            </div>

            <div className="flex flex-wrap gap-2 items-center">
              <span className="font-body text-xs uppercase tracking-wide-body text-px-label">Size</span>
              {(['sm', 'md', 'lg'] as const).map((option) => (
                <Button
                  key={option}
                  theme={args.theme}
                  size="sm"
                  variant={size === option ? 'primary' : 'ghost'}
                  onClick={() => setSize(option)}
                >
                  {option.toUpperCase()}
                </Button>
              ))}
            </div>

            <div className="flex flex-wrap gap-2 items-center">
              <Button
                theme={args.theme}
                size="sm"
                variant={closeOnOverlayClick ? 'ghost-primary' : 'ghost'}
                onClick={() => setCloseOnOverlayClick((value) => !value)}
              >
                {closeOnOverlayClick ? 'Overlay Click: Enabled' : 'Overlay Click: Disabled'}
              </Button>
              <Button theme={args.theme} size="sm" onClick={() => setOpen(true)}>
                Open Modal
              </Button>
            </div>
          </div>
        </div>

        <Modal
          {...args}
          open={open}
          size={size}
          closeOnOverlayClick={closeOnOverlayClick}
          onClose={() => setOpen(false)}
          title={`Broadcast Settings (${size.toUpperCase()})`}
          footer={
            <div className="flex gap-3">
              <Button variant="ghost" size="sm" theme={args.theme} onClick={() => setOpen(false)}>
                Cancel
              </Button>
              <Button variant="primary" size="sm" theme={args.theme} onClick={() => setOpen(false)}>
                Save Changes
              </Button>
            </div>
          }
        >
          <p className="font-body text-sm text-px-label">
            Configure stream overlays, bracket visibility, and caster notes for the next map.
          </p>
        </Modal>
      </div>
    );
  },
};

export const SizeMatrix: Story = {
  render: (args) => (
    <div className="space-y-4">
      <Modal {...args} onClose={() => {}} size="sm" title="Small modal">
        <p className="font-body text-sm text-px-label">Small width.</p>
      </Modal>
    </div>
  ),
};

export const LightTheme: Story = {
  args: {
    theme: 'light',
    onClose: () => {},
  },
  parameters: { backgrounds: { default: 'light' } },
};

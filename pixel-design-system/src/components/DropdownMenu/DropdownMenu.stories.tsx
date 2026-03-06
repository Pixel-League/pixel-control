import { useState } from 'react';
import type { Meta, StoryObj } from '@storybook/react';
import { DropdownMenu, type DropdownMenuItem } from './DropdownMenu';
import { Button } from '../Button/Button';

type MenuPreset = 'match' | 'account';

const presetItems: Record<MenuPreset, DropdownMenuItem[]> = {
  match: [
    { label: 'Edit match' },
    { label: 'Duplicate' },
    { label: 'Export CSV' },
    { divider: true, label: '' },
    { label: 'Delete match', danger: true },
  ],
  account: [
    { label: 'Profile' },
    { label: 'Team settings' },
    { label: 'Billing', disabled: true },
    { divider: true, label: '' },
    { label: 'Sign out', danger: true },
  ],
};

const meta: Meta<typeof DropdownMenu> = {
  title: 'Components/DropdownMenu',
  component: DropdownMenu,
  argTypes: {
    align: { control: 'select', options: ['left', 'right'] },
    theme: { control: 'select', options: ['dark', 'light'] },
  },
};

export default meta;
type Story = StoryObj<typeof DropdownMenu>;

export const Default: Story = {
  args: {
    trigger: <Button variant="secondary" size="sm">Actions</Button>,
    items: [
      { label: 'Edit match' },
      { label: 'Duplicate' },
      { label: 'Export CSV' },
      { divider: true, label: '' },
      { label: 'Delete', danger: true },
    ],
  },
};

export const RightAligned: Story = {
  args: {
    align: 'right',
    trigger: <Button variant="ghost" size="sm">Menu</Button>,
    items: [
      { label: 'Profile' },
      { label: 'Settings' },
      { divider: true, label: '' },
      { label: 'Sign out', danger: true },
    ],
  },
};

export const WithDisabledItems: Story = {
  args: {
    trigger: <Button variant="secondary" size="sm">Options</Button>,
    items: [
      { label: 'Available' },
      { label: 'Locked', disabled: true },
      { label: 'Also locked', disabled: true },
    ],
  },
};

export const PresentationPage: Story = {
  args: { theme: 'dark' },
  render: function PresentationPageStory(args) {
    const [align, setAlign] = useState<'left' | 'right'>('left');
    const [preset, setPreset] = useState<MenuPreset>('match');
    const [lastAction, setLastAction] = useState('No menu action yet.');

    const panelThemeClasses =
      args.theme === 'light'
        ? 'bg-nm-light border border-black/[0.08] shadow-nm-flat-l text-px-offblack'
        : 'bg-nm-dark border border-white/[0.08] shadow-nm-flat-d text-px-white';

    const items = presetItems[preset].map((item) => {
      if (item.divider) return item;
      return {
        ...item,
        onClick: () => setLastAction(`Action: ${item.label}`),
      };
    });

    return (
      <div className="p-6 min-h-[420px] flex flex-col gap-6">
        <div className={`max-w-3xl p-5 ${panelThemeClasses}`}>
          <div className="space-y-4">
            <div>
              <p className="font-display text-xl font-bold uppercase tracking-display">
                Dropdown Playground
              </p>
              <p className="font-body text-xs text-px-label mt-1">
                Open the menu from the trigger button, then switch alignment and preset item sets.
              </p>
            </div>

            <div className="flex flex-wrap gap-2 items-center">
              <Button
                size="sm"
                theme={args.theme}
                variant={align === 'left' ? 'primary' : 'ghost'}
                onClick={() => setAlign('left')}
              >
                Align Left
              </Button>
              <Button
                size="sm"
                theme={args.theme}
                variant={align === 'right' ? 'primary' : 'ghost'}
                onClick={() => setAlign('right')}
              >
                Align Right
              </Button>
            </div>

            <div className="flex flex-wrap gap-2 items-center">
              <Button
                size="sm"
                theme={args.theme}
                variant={preset === 'match' ? 'ghost-primary' : 'ghost'}
                onClick={() => setPreset('match')}
              >
                Match Actions
              </Button>
              <Button
                size="sm"
                theme={args.theme}
                variant={preset === 'account' ? 'ghost-primary' : 'ghost'}
                onClick={() => setPreset('account')}
              >
                Account Actions
              </Button>
            </div>

            <p className="font-body text-xs text-px-label">{lastAction}</p>
          </div>
        </div>

        <div className="flex-1 min-h-[180px] flex items-start">
          <DropdownMenu
            {...args}
            theme={args.theme}
            align={align}
            trigger={<Button variant="secondary" size="sm" theme={args.theme}>Open Menu</Button>}
            items={items}
          />
        </div>
      </div>
    );
  },
};

export const LightTheme: Story = {
  args: {
    theme: 'light',
    trigger: <Button variant="secondary" size="sm" theme="light">Actions</Button>,
    items: [
      { label: 'Edit' },
      { label: 'Delete', danger: true },
    ],
  },
  parameters: { backgrounds: { default: 'light' } },
};

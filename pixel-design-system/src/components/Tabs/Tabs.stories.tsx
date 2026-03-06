import type { Meta, StoryObj } from '@storybook/react';
import { Tabs } from './Tabs';

const sampleTabs = [
  {
    label: 'Planning',
    content: <p className="font-body text-sm text-px-label">Tab content - planning view active.</p>,
  },
  {
    label: 'Ranking',
    content: <p className="font-body text-sm text-px-label">Live ranking and points.</p>,
  },
  {
    label: 'Results',
    content: <p className="font-body text-sm text-px-label">Latest results and recap.</p>,
  },
];

const meta: Meta<typeof Tabs> = {
  title: 'Components/Tabs',
  component: Tabs,
  argTypes: {
    theme: { control: 'select', options: ['dark', 'light'] },
  },
  args: {
    tabs: sampleTabs,
  },
};

export default meta;
type Story = StoryObj<typeof Tabs>;

export const Default: Story = {};

export const SecondTabActive: Story = {
  args: {
    defaultIndex: 1,
  },
};

export const LightTheme: Story = {
  args: {
    theme: 'light',
  },
  parameters: {
    backgrounds: { default: 'light' },
  },
};

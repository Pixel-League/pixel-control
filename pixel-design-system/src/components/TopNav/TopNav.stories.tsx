import type { Meta, StoryObj } from '@storybook/react';
import { TopNav } from './TopNav';
import { Button } from '../Button/Button';

const meta: Meta<typeof TopNav> = {
  title: 'Components/TopNav',
  component: TopNav,
  argTypes: {
    theme: { control: 'select', options: ['dark', 'light'] },
  },
};

export default meta;
type Story = StoryObj<typeof TopNav>;

const DefaultBrand = ({ isDark }: { isDark?: boolean }) => (
  <>
    <span className="font-display text-2xl font-bold tracking-display text-px-primary uppercase">
      PIXEL
    </span>
    <span
      className={`font-display text-2xl font-bold tracking-display uppercase ${
        isDark !== false ? 'text-px-white' : 'text-px-offblack'
      }`}
    >
      SERIES
    </span>
  </>
);

export const Default: Story = {
  args: {
    brand: <DefaultBrand />,
    links: [
      { label: 'Planning', active: true },
      { label: 'Ranking' },
      { label: 'Bracket' },
    ],
    actions: (
      <Button variant="primary" size="sm">
        Register
      </Button>
    ),
  },
};

export const WithoutActions: Story = {
  args: {
    brand: <DefaultBrand />,
    links: [
      { label: 'Schedule', active: true },
      { label: 'Results' },
      { label: 'Teams' },
    ],
  },
};

export const LinksOnly: Story = {
  args: {
    brand: <DefaultBrand />,
    links: [
      { label: 'Home' },
      { label: 'Stats' },
    ],
  },
};

export const LightTheme: Story = {
  args: {
    brand: <DefaultBrand isDark={false} />,
    theme: 'light',
    links: [
      { label: 'Planning', active: true },
      { label: 'Ranking' },
      { label: 'Bracket' },
    ],
    actions: (
      <Button variant="primary" size="sm" theme="light">
        Register
      </Button>
    ),
  },
  parameters: { backgrounds: { default: 'light' } },
};

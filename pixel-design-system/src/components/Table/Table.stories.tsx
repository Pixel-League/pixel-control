import type { Meta, StoryObj } from '@storybook/react';
import { Table, type TableColumn } from './Table';

interface TeamRow {
  rank: number;
  team: string;
  wins: number;
  losses: number;
  pts: number;
}

const sampleData: TeamRow[] = [
  { rank: 1, team: 'OWNED', wins: 12, losses: 2, pts: 36 },
  { rank: 2, team: 'CRYSTAL', wins: 10, losses: 4, pts: 30 },
  { rank: 3, team: 'EDEN', wins: 8, losses: 6, pts: 24 },
  { rank: 4, team: 'HYPSTER', wins: 6, losses: 8, pts: 18 },
];

const columns: TableColumn<TeamRow>[] = [
  { key: 'rank', header: '#' },
  { key: 'team', header: 'Team' },
  {
    key: 'wins',
    header: 'W',
    render: (v) => <span className="text-px-success">{String(v)}</span>,
  },
  {
    key: 'losses',
    header: 'L',
    render: (v) => <span className="text-px-error">{String(v)}</span>,
  },
  {
    key: 'pts',
    header: 'Pts',
    render: (v) => <span className="font-bold">{String(v)}</span>,
  },
];

const meta: Meta = {
  title: 'Components/Table',
  component: Table,
  argTypes: {
    theme: { control: 'select', options: ['dark', 'light'] },
    striped: { control: 'boolean' },
    hoverable: { control: 'boolean' },
  },
};

export default meta;
type Story = StoryObj;

export const Default: Story = {
  render: (args) => (
    <Table<TeamRow> {...args} columns={columns} data={sampleData} rowKey="team" />
  ),
};

export const Striped: Story = {
  render: (args) => (
    <Table<TeamRow> {...args} columns={columns} data={sampleData} rowKey="team" striped />
  ),
};

export const LightTheme: Story = {
  render: (args) => (
    <Table<TeamRow> {...args} columns={columns} data={sampleData} rowKey="team" theme="light" />
  ),
  parameters: { backgrounds: { default: 'light' } },
};

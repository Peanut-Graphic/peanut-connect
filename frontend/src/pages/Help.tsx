import { Layout } from '@/components/layout';
import { Card } from '@/components/common';
import CampaignBuilderGuide from '@/components/CampaignBuilderGuide';

export default function Help() {
  return (
    <Layout
      title="Help"
      description="How-to guides for the campaign tools."
    >
      <Card className="p-6 max-w-3xl">
        <h2 className="text-lg font-semibold text-slate-900 mb-1">Campaign Link Builder</h2>
        <p className="text-sm text-slate-500 mb-4">
          Create a tracked short link and QR code for a campaign — step by step.
        </p>
        <CampaignBuilderGuide />
      </Card>
    </Layout>
  );
}

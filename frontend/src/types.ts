export type Obituary = {
  id: number | string;
  source_id: string;
  source_url: string;
  title: string;
  person_name: string;
  excerpt: string;
  content?: string;
  image_url?: string | null;
  death_date?: string | null;
  published_at?: string | null;
  created_at?: string | null;
};

export type PublicConfig = {
  app_base_url: string;
  final_site_url: string;
  current_site_url: string;
  vapid_public_key: string;
  contact: {
    phone: string;
    email: string;
    address: string;
    official_contact_url: string;
  };
};

export type SympathyMessage = {
  id: number | string;
  obituary_source_id: string;
  author_name: string;
  message: string;
  status: string;
  posted_at?: string | null;
  created_at?: string | null;
};

export type SympathyMessageInput = {
  obituary_source_id: string;
  author_name: string;
  author_email: string;
  author_phone?: string;
  message: string;
  website?: string;
};

export type ApiListResponse<T> = {
  data: T[];
  meta?: Record<string, unknown>;
};

export type ApiItemResponse<T> = {
  data: T;
};

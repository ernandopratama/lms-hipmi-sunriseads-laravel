import type { Component } from "vue";

export type SchoolOption = {
  id: string;
  name: string;
  code: string;
  level: string;
  status: string;
  city?: string | null;
  province?: string | null;
};

export type Permission = { resource?: string; action?: string };

export type SessionProfile = {
  id?: string;
  email?: string;
  firstName?: string;
  lastName?: string;
  roles?: string[];
  permissions?: Permission[];
  schoolId?: string | null;
};

export type NavItem = { href: string; label: string; description: string; icon: Component };
export type NavGroup = { label: string; items: NavItem[] };

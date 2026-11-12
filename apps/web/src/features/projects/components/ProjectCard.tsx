import { ImageOff } from 'lucide-react'
import { Link } from 'react-router-dom'
import { timeAgo } from '@/shared/lib/time'
import { Badge } from '@/shared/ui/Badge'
import type { Project } from '../api/projects'

/** A project in the list: cover image, name, image count and when it last changed. */
export function ProjectCard({ project }: { project: Project }) {
  const count = project.image_count ?? 0

  return (
    <li>
      <Link
        to={`/create/${project.id}`}
        className="group block overflow-hidden rounded-xl border border-line bg-raised transition-colors hover:bg-sunken"
      >
        <div className="aspect-[4/3] bg-sunken">
          {project.cover_thumbnail_url ? (
            <img
              src={project.cover_thumbnail_url}
              alt=""
              loading="lazy"
              className="size-full object-cover"
            />
          ) : (
            <div className="grid size-full place-items-center text-muted">
              <ImageOff aria-hidden="true" className="size-6" />
              <span className="sr-only">No images yet</span>
            </div>
          )}
        </div>
        <div className="p-4">
          <div className="flex items-start justify-between gap-2">
            <h3 className="min-w-0 truncate font-display text-base font-semibold">
              {project.title || 'Untitled project'}
            </h3>
            <Badge>{project.status === 'draft' ? 'Draft' : project.status}</Badge>
          </div>
          <p className="mt-1 text-sm text-muted">
            {count} {count === 1 ? 'image' : 'images'}
            {project.updated_at ? ` · updated ${timeAgo(project.updated_at)}` : ''}
          </p>
        </div>
      </Link>
    </li>
  )
}

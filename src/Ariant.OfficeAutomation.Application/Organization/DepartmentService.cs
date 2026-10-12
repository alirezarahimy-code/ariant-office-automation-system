using System.Globalization;
using Ariant.OfficeAutomation.Application.Abstractions;
using Ariant.OfficeAutomation.Application.Common;
using Ariant.OfficeAutomation.Application.Organization.Dtos;
using Ariant.OfficeAutomation.Domain.Organization;
using Microsoft.EntityFrameworkCore;

namespace Ariant.OfficeAutomation.Application.Organization;

/// <summary>پیاده‌سازی سرویس ساختار سازمانی.</summary>
public sealed class DepartmentService : IDepartmentService
{
    private const int MaxTreeDepth = 64;

    private static readonly StringComparer PersianComparer = CreatePersianComparer();

    private readonly IAppDbContext _context;
    private readonly IDateTimeProvider _dateTimeProvider;

    /// <summary>ساخت سرویس.</summary>
    public DepartmentService(IAppDbContext context, IDateTimeProvider dateTimeProvider)
    {
        _context = context;
        _dateTimeProvider = dateTimeProvider;
    }

    /// <summary>مقایسه‌کننده متن فارسی برای مرتب‌سازی صحیح الفبایی (با احتیاط در نبود ICU).</summary>
    private static StringComparer CreatePersianComparer()
    {
        try
        {
            return StringComparer.Create(CultureInfo.GetCultureInfo("fa-IR"), ignoreCase: true);
        }
        catch (ArgumentException)
        {
            return StringComparer.OrdinalIgnoreCase;
        }
    }

    /// <inheritdoc />
    public async Task<PagedResult<DepartmentListItemDto>> GetPagedAsync(DepartmentQuery query, CancellationToken cancellationToken = default)
    {
        IQueryable<Department> departments = _context.Departments.AsNoTracking();

        if (query.IsActive.HasValue)
            departments = departments.Where(d => d.IsActive == query.IsActive.Value);

        if (query.ParentId.HasValue)
            departments = departments.Where(d => d.ParentId == query.ParentId.Value);

        string? term = query.Search?.Trim();
        if (!string.IsNullOrWhiteSpace(term))
        {
            departments = departments.Where(d =>
                EF.Functions.Like(d.Title, $"%{term}%") ||
                (d.Code != null && EF.Functions.Like(d.Code, $"%{term}%")));
        }

        int totalCount = await departments.CountAsync(cancellationToken);

        List<DepartmentListItemDto> items = await departments
            .OrderBy(d => d.SortOrder)
            .ThenBy(d => d.Title)
            .Skip(query.Skip)
            .Take(query.PageSize)
            .Select(d => new DepartmentListItemDto
            {
                Id = d.Id,
                Title = d.Title,
                Code = d.Code,
                ParentTitle = d.Parent != null ? d.Parent.Title : null,
                EmployeeCount = d.Employees.Count,
                SortOrder = d.SortOrder,
                IsActive = d.IsActive,
                CreatedAtUtc = d.CreatedAtUtc
            })
            .ToListAsync(cancellationToken);

        return new PagedResult<DepartmentListItemDto>(items, totalCount, query.Page, query.PageSize);
    }

    /// <inheritdoc />
    public async Task<IReadOnlyList<DepartmentTreeItemDto>> GetTreeAsync(CancellationToken cancellationToken = default)
    {
        var rows = await _context.Departments
            .AsNoTracking()
            .Select(d => new
            {
                d.Id,
                d.Title,
                d.Code,
                d.IsActive,
                d.SortOrder,
                d.ParentId,
                EmployeeCount = d.Employees.Count
            })
            .ToListAsync(cancellationToken);

        var nodes = new Dictionary<Guid, DepartmentTreeItemDto>(rows.Count);
        foreach (var row in rows)
        {
            nodes[row.Id] = new DepartmentTreeItemDto
            {
                Id = row.Id,
                Title = row.Title,
                Code = row.Code,
                IsActive = row.IsActive,
                EmployeeCount = row.EmployeeCount
            };
        }

        var sortOrder = rows.ToDictionary(r => r.Id, r => r.SortOrder);
        var roots = new List<DepartmentTreeItemDto>();

        foreach (var row in rows)
        {
            if (row.ParentId.HasValue &&
                nodes.TryGetValue(row.ParentId.Value, out DepartmentTreeItemDto? parent) &&
                parent.Id != row.Id)
            {
                parent.Children.Add(nodes[row.Id]);
            }
            else
            {
                roots.Add(nodes[row.Id]);
            }
        }

        var visited = new HashSet<Guid>();
        SortTree(roots, sortOrder, visited, 0);

        return roots;
    }

    private void SortTree(List<DepartmentTreeItemDto> nodes, Dictionary<Guid, int> sortOrder, HashSet<Guid> visited, int depth)
    {
        nodes.Sort((left, right) =>
        {
            int byOrder = sortOrder[left.Id].CompareTo(sortOrder[right.Id]);
            return byOrder != 0 ? byOrder : PersianComparer.Compare(left.Title, right.Title);
        });

        foreach (DepartmentTreeItemDto node in nodes)
        {
            node.Depth = depth;

            // محافظت در برابر داده معیوب (حلقه در درخت) و عمق غیرمجاز
            if (!visited.Add(node.Id) || depth >= MaxTreeDepth)
            {
                node.Children.Clear();
                continue;
            }

            if (node.Children.Count > 0)
                SortTree(node.Children, sortOrder, visited, depth + 1);
        }
    }

    /// <inheritdoc />
    public async Task<DepartmentDetailDto?> GetByIdAsync(Guid id, CancellationToken cancellationToken = default)
        => await _context.Departments
            .AsNoTracking()
            .Where(d => d.Id == id)
            .Select(d => new DepartmentDetailDto
            {
                Id = d.Id,
                Title = d.Title,
                Code = d.Code,
                ParentId = d.ParentId,
                ParentTitle = d.Parent != null ? d.Parent.Title : null,
                Description = d.Description,
                SortOrder = d.SortOrder,
                IsActive = d.IsActive,
                EmployeeCount = d.Employees.Count,
                CreatedAtUtc = d.CreatedAtUtc,
                UpdatedAtUtc = d.UpdatedAtUtc
            })
            .FirstOrDefaultAsync(cancellationToken);

    /// <inheritdoc />
    public async Task<Result<Guid>> CreateAsync(DepartmentFormRequest request, CancellationToken cancellationToken = default)
    {
        string title = (request.Title ?? string.Empty).Trim();
        if (string.IsNullOrWhiteSpace(title))
            return Result.Failure<Guid>("نام واحد الزامی است.");

        if (request.ParentId.HasValue)
        {
            bool parentExists = await _context.Departments
                .AnyAsync(d => d.Id == request.ParentId.Value, cancellationToken);

            if (!parentExists)
                return Result.Failure<Guid>("واحد بالادستی انتخاب‌شده یافت نشد.");
        }

        bool isDuplicate = await _context.Departments
            .AnyAsync(d => d.ParentId == request.ParentId && d.Title == title, cancellationToken);

        if (isDuplicate)
            return Result.Failure<Guid>("واحدی با همین نام در این سطح از ساختار سازمانی وجود دارد.");

        var department = new Department
        {
            Title = title,
            Code = NullIfEmpty(request.Code),
            ParentId = request.ParentId,
            Description = NullIfEmpty(request.Description),
            SortOrder = request.SortOrder,
            IsActive = request.IsActive
        };

        _context.Departments.Add(department);
        await _context.SaveChangesAsync(cancellationToken);

        return Result.Success(department.Id);
    }

    /// <inheritdoc />
    public async Task<Result> UpdateAsync(Guid id, DepartmentFormRequest request, CancellationToken cancellationToken = default)
    {
        Department? department = await _context.Departments
            .FirstOrDefaultAsync(d => d.Id == id, cancellationToken);

        if (department is null)
            return Result.Failure("واحد سازمانی یافت نشد.");

        string title = (request.Title ?? string.Empty).Trim();
        if (string.IsNullOrWhiteSpace(title))
            return Result.Failure("نام واحد الزامی است.");

        if (request.ParentId.HasValue)
        {
            if (request.ParentId.Value == id)
                return Result.Failure("یک واحد نمی‌تواند والد خودش باشد.");

            bool parentExists = await _context.Departments
                .AnyAsync(d => d.Id == request.ParentId.Value, cancellationToken);

            if (!parentExists)
                return Result.Failure("واحد بالادستی انتخاب‌شده یافت نشد.");

            bool createsCycle = await IsDescendantAsync(request.ParentId.Value, id, cancellationToken);
            if (createsCycle)
                return Result.Failure("واحد بالادستی انتخاب‌شده زیرمجموعه همین واحد است و ساختار درخت را خراب می‌کند.");
        }

        bool isDuplicate = await _context.Departments
            .AnyAsync(d => d.Id != id && d.ParentId == request.ParentId && d.Title == title, cancellationToken);

        if (isDuplicate)
            return Result.Failure("واحدی با همین نام در این سطح از ساختار سازمانی وجود دارد.");

        department.Title = title;
        department.Code = NullIfEmpty(request.Code);
        department.ParentId = request.ParentId;
        department.Description = NullIfEmpty(request.Description);
        department.SortOrder = request.SortOrder;
        department.IsActive = request.IsActive;

        await _context.SaveChangesAsync(cancellationToken);
        return Result.Success();
    }

    /// <inheritdoc />
    public async Task<Result> DeleteAsync(Guid id, CancellationToken cancellationToken = default)
    {
        Department? department = await _context.Departments
            .FirstOrDefaultAsync(d => d.Id == id, cancellationToken);

        if (department is null)
            return Result.Failure("واحد سازمانی یافت نشد.");

        bool hasChildren = await _context.Departments.AnyAsync(d => d.ParentId == id, cancellationToken);
        if (hasChildren)
            return Result.Failure("این واحد دارای زیرمجموعه است؛ ابتدا واحدهای زیرمجموعه را جابه‌جا یا حذف کنید.");

        bool hasEmployees = await _context.Employees.AnyAsync(e => e.DepartmentId == id, cancellationToken);
        if (hasEmployees)
            return Result.Failure("این واحد دارای کارمند است؛ ابتدا کارمندان را به واحد دیگری منتقل کنید.");

        // حذف نرم: داده حذف نمی‌شود، فقط از دید کاربر پنهان می‌گردد
        department.IsDeleted = true;
        department.DeletedAtUtc = _dateTimeProvider.UtcNow;

        await _context.SaveChangesAsync(cancellationToken);
        return Result.Success();
    }

    /// <inheritdoc />
    public async Task<IReadOnlyList<SelectOptionDto>> GetOptionsAsync(Guid? excludeId = null, CancellationToken cancellationToken = default)
    {
        var rows = await _context.Departments
            .AsNoTracking()
            .Select(d => new { d.Id, d.Title, d.ParentId, d.SortOrder })
            .ToListAsync(cancellationToken);

        var childrenLookup = rows
            .GroupBy(r => r.ParentId)
            .ToDictionary(g => g.Key, g => g.ToList());

        var options = new List<SelectOptionDto>();
        var visited = new HashSet<Guid>();

        void Walk(Guid? parentId, int depth)
        {
            if (depth > MaxTreeDepth || !childrenLookup.TryGetValue(parentId, out var children))
                return;

            foreach (var child in children
                         .OrderBy(c => c.SortOrder)
                         .ThenBy(c => c.Title, PersianComparer))
            {
                if (!visited.Add(child.Id))
                    continue;

                if (child.Id != excludeId)
                {
                    string prefix = depth == 0 ? string.Empty : new string('—', depth) + " ";
                    options.Add(SelectOptionDto.Create(child.Id.ToString(), prefix + child.Title));
                }

                Walk(child.Id, depth + 1);
            }
        }

        Walk(null, 0);
        return options;
    }

    /// <inheritdoc />
    public async Task<bool> IsDescendantAsync(Guid candidateChildId, Guid ancestorId, CancellationToken cancellationToken = default)
    {
        if (candidateChildId == ancestorId)
            return true;

        // درخت کوچک است؛ پیمایش گام‌به‌گام ساده‌تر و سریع‌تر از یک پرس‌وجوی بازگشتی است
        var parents = await _context.Departments
            .AsNoTracking()
            .Select(d => new { d.Id, d.ParentId })
            .ToDictionaryAsync(d => d.Id, d => d.ParentId, cancellationToken);

        Guid current = candidateChildId;
        int guard = 0;

        while (parents.TryGetValue(current, out Guid? parentId) && parentId.HasValue)
        {
            if (parentId.Value == ancestorId)
                return true;

            current = parentId.Value;

            if (++guard > MaxTreeDepth)
                return false;
        }

        return false;
    }

    private static string? NullIfEmpty(string? value)
        => string.IsNullOrWhiteSpace(value) ? null : value.Trim();
}
